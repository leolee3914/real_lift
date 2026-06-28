<?php

declare(strict_types=1);

namespace real_lift;

use pocketmine\block\BaseSign;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\RedstoneLamp;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\form\Form as PMForm;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\SpawnParticleEffectPacket;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\Position;
use pocketmine\world\World;

use function abs;
use function array_shift;
use function array_unshift;
use function count;
use function hrtime;
use function is_int;
use function mkdir;
use function pack;
use function strtolower;

class Main extends PluginBase implements Listener {

	const MOVEMENT_UP = 0;
	const MOVEMENT_DOWN = 1;
	const MOVEMENT_STOP = 2;

	/** @var int[][] */
	const QUEUE_CHECK_XZ_REDSTONE_LAMP = [
		[1, 0], [0, 1], [-1, 0], [0, -1],
	];

	/** @var int[][] */
	const QUEUE_CHECK_XZ_SIGN = [
		[1, 1], [-1, 1], [1, -1], [-1, -1],
		[2, 2], [-2, 2], [2, -2], [-2, -2],
		[3, 3], [-3, 3], [3, -3], [-3, -3],
	];

	private static self $instance;

	public static function getInstance () : self {
		return self::$instance;
	}

	public bool $multiple_floors_mode, $enable3x3, $enable5x5, $tp_entity;

	/** @var array<string, MovingLift> - [liftHash => class] */
	public array $movingLift = [];

	/** @var array<string, int> - [n => hrtime(true)] */
	private array $sendFormCoolDown = [];

	public function onEnable () : void {
		self::$instance = $this;

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		@mkdir($this->getDataFolder());
		$config = new Config($this->getDataFolder() . 'config.yml', Config::YAML, []);
		foreach ( [
			'multiple_floors_mode' => true,
			'enable3x3' => true,
			'enable5x5' => false,
			'tp_entity' => true,
		] as $key => $defaultValue ) {
			if ( !$config->exists($key) ) {
				$config->set($key, $defaultValue);
				$config->save();
			}
		}
		$this->multiple_floors_mode = (bool) $config->get('multiple_floors_mode');
		$this->enable3x3 = (bool) $config->get('enable3x3');
		$this->enable5x5 = (bool) $config->get('enable5x5');
		$this->tp_entity = (bool) $config->get('tp_entity');

		$this->saveResource('locale.yml');
		$this->saveResource('locale-eng.yml');
		$this->saveResource('locale-zh-TW.yml');
		$locale = new Config($this->getDataFolder() . 'locale.yml', Config::YAML, []);
		Locale::setData($locale->getAll());

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask($this->moveLift(...)), 1);
	}

	public function pq ( PlayerQuitEvent $e ) : void {
		$p = $e->getPlayer();
		$n = $p->getName();

		unset($this->sendFormCoolDown[$n]);
	}

	public static function createPlaySoundPacket ( Vector3 $pos, string $sound, float $vol = 1.0, float $pitch = 1.0 ) : PlaySoundPacket {
		return PlaySoundPacket::create(
			$sound,
			$pos->x,
			$pos->y,
			$pos->z,
			$vol,
			$pitch,
			null,
		);
	}

	public static function createParticlePacket ( Vector3 $pos, string $particleName ) : SpawnParticleEffectPacket {
		$pk = new SpawnParticleEffectPacket();
		$pk->position = $pos;
		$pk->particleName = $particleName;
		$pk->molangVariablesJson = null;

		return $pk;
	}

	private function moveLift () : void {
		foreach ( $this->movingLift as $hash => $movingLift ) {
			$liftPos = $movingLift->position;
			$world = $liftPos->world;
			if ( !$world->isLoaded() or !$this->isLiftColumn($world, $liftPos) ) {
				unset($this->movingLift[$hash]);
				continue;
			}
			$liftPosX = $liftPos->getFloorX();
			$liftPosY = $liftPos->getFloorY();
			$liftPosZ = $liftPos->getFloorZ();

			$hasQueue = (count($movingLift->queue) > 0);
			if ( $hasQueue ) {
				foreach ( $movingLift->queue as $queueEntry ) {
					if ( --$queueEntry->buttonBlinkTimer <= 0 ) {
						$queueEntryPos = $queueEntry->position;

						$block = $world->getBlockAt($queueEntryPos->getFloorX(), $queueEntryPos->getFloorY(), $queueEntryPos->getFloorZ(), false, false);
						if ( $block instanceof BaseSign ) {
							$world->broadcastPacketToViewers($queueEntryPos, self::createParticlePacket($queueEntryPos->add(0.5, 0.5, 0.5), 'minecraft:redstone_ore_dust_particle'));
						} else {
							if ( $block->getTypeId() === BlockTypeIds::REDSTONE_LAMP and $block instanceof RedstoneLamp ) {
								$block->setPowered(!$block->isPowered());

								$world->setBlockAt($queueEntryPos->getFloorX(), $queueEntryPos->getFloorY(), $queueEntryPos->getFloorZ(), $block, false);
							}
						}
						$queueEntry->buttonBlinkTimer = 6;
					}
				}
			}

			if ( $movingLift->unset ) {
				if ( $hasQueue ) {
					$first = true;
					foreach ( $movingLift->queue as $queueY => $queueEntry ) {
						if ( $first and $movingLift->hasQueue ) {
							$queueEntryPos = $queueEntry->position;

							$first = false;

							$block = $world->getBlockAt($queueEntryPos->getFloorX(), $queueEntryPos->getFloorY(), $queueEntryPos->getFloorZ(), false, false);
							if ( $block->getTypeId() === BlockTypeIds::REDSTONE_LAMP and $block instanceof RedstoneLamp ) {
								if ( $block->isPowered() ) {
									$block->setPowered(false);
									$world->setBlockAt($queueEntryPos->getFloorX(), $queueEntryPos->getFloorY(), $queueEntryPos->getFloorZ(), $block, false);
								}
							}
							unset($movingLift->queue[$queueY]);
						} else {
							if ( $liftPosY > $queueEntry->getTargetY() ) {
								$movingLift->movement = self::MOVEMENT_DOWN;
								$movingLift->waiting = null;
								$movingLift->targetY = $queueEntry->getTargetY();
							} elseif ( $liftPosY < $queueEntry->getTargetY() ) {
								$movingLift->movement = self::MOVEMENT_UP;
								$movingLift->waiting = null;
								$movingLift->targetY = $queueEntry->getTargetY();
							}
							$movingLift->unset = false;
							$movingLift->hasQueue = true;
							$movingLift->fastMode = true;
							break;
						}
					}
				}
				if ( $movingLift->unset ) {
					unset($this->movingLift[$hash]);
				}
				continue;
			}
			if ( $movingLift->waiting !== null ) {
				if ( --$movingLift->waiting <= 0 ) {
					$movingLift->unset = true;
				}
				if ( $movingLift->waiting === 20 and $movingLift->playSound ) {
					$world->broadcastPacketToViewers($liftPos, self::createPlaySoundPacket($liftPos, 'random.orb', 1, 2));
				}
				continue;
			}
			$this->checkLiftEntity($world, $hash);
			$insideEntities = $movingLift->insideEntities;
			foreach ( $insideEntities as $entity ) {
				$entity->resetFallDistance();
			}
			$canMove = true;
			if ( $movingLift->movement === self::MOVEMENT_UP ) {
				foreach ( $insideEntities as $p ) {
					if ( $p instanceof Player ) {
						$p->setMotion(new Vector3(0, 0.8, 0));
						if ( $p->getPosition()->y < ($liftPosY - 2.4) ) {
							$canMove = false;
						}
					}
				}
			} elseif ( $movingLift->movement === self::MOVEMENT_DOWN ) {
				foreach ( $insideEntities as $p ) {
					if ( $p instanceof Player ) {
						$p->setMotion(new Vector3(0, -0.4, 0));
						if ( $p->getPosition()->y > ($liftPosY - 3) ) {
							$canMove = false;
						}
					}
				}
			}
			$offset = match ( $movingLift->getSize() ) {
				5 => 2,
				3 => 1,
				default => 0,
			};

			if ( $hasQueue and !$movingLift->fastMode ) {
				$movingLift->fastMode = true;
			}
			if ( $movingLift->fastMode ) {
				$swapBlock = $this->swapBlock($movingLift, $movingLift->targetY - $liftPosY, $offset);
				if ( $swapBlock ) {
					$movingLift->moving = true;
					continue;
				} else {
					$movingLift->fastMode = false;
				}
			}
			$fillerBlockIds = [];
			$stop = false;
			if ( $canMove and $movingLift->movement === self::MOVEMENT_UP ) {
				if ( ($liftPosY + 1) >= $world->getMaxY() or $liftPosY === $movingLift->targetY ) {
					$stop = true;
				}
				for ( $X = -$offset; $X <= $offset; ++$X ) {
					for ( $Z = -$offset; $Z <= $offset; ++$Z ) {
						$fillerBlockIds[] = $curFillerBlockId = $world->getBlockAt($liftPosX + $X, $liftPosY + 1, $liftPosZ + $Z, false, false)->getTypeId();
						if ( $stop or ($curFillerBlockId !== BlockTypeIds::AIR and $curFillerBlockId !== BlockTypeIds::GLASS) ) {
							$stop = true;
							break 2;
						}
					}
				}
			} elseif ( $canMove and $movingLift->movement === self::MOVEMENT_DOWN ) {
				if ( ($liftPosY - 5) <= $world->getMinY() or $liftPosY === $movingLift->targetY ) {
					$stop = true;
				}
				for ( $X = -$offset; $X <= $offset; ++$X ) {
					for ( $Z = -$offset; $Z <= $offset; ++$Z ) {
						$fillerBlockIds[] = $curFillerBlockId = $world->getBlockAt($liftPosX + $X, $liftPosY - 6, $liftPosZ + $Z, false, false)->getTypeId();
						if ( $stop or ($curFillerBlockId !== BlockTypeIds::AIR and $curFillerBlockId !== BlockTypeIds::GLASS) ) {
							$stop = true;
							break 2;
						}
					}
				}
			} else {
				$canMove = false;
			}
			if ( $stop ) {
				if ( !$movingLift->moving ) {
					$movingLift->unset = true;
					continue;
				}
				$movingLift->movement = self::MOVEMENT_STOP;
				$movingLift->waiting = 40;
				$movingLift->playSound = true;
				continue;
			}
			if ( $canMove ) {
				if ( $this->getLiftSizeByPosition($world, $liftPos) !== $movingLift->getSize() ) {
					unset($this->movingLift[$hash]);
					continue;
				}
				if ( $movingLift->movement === self::MOVEMENT_UP ) {
					$fillerBlockIdsIndex = 0;
					$airBlock = VanillaBlocks::AIR();
					$glassBlock = VanillaBlocks::GLASS();
					for ( $X = -$offset; $X <= $offset; ++$X ) {
						for ( $Z = -$offset; $Z <= $offset; ++$Z ) {
							$setBlock = (($X === 0 and $Z === 0) ? VanillaBlocks::GOLD() : VanillaBlocks::IRON());
							$world->setBlockAt($liftPosX + $X, $liftPosY, $liftPosZ + $Z, $airBlock, false);
							$world->setBlockAt($liftPosX + $X, $liftPosY + 1, $liftPosZ + $Z, $setBlock, false);
							$world->setBlockAt($liftPosX + $X, $liftPosY - 5, $liftPosZ + $Z, $fillerBlockIds[$fillerBlockIdsIndex++] === BlockTypeIds::GLASS ? $glassBlock : $airBlock, false);
							$world->setBlockAt($liftPosX + $X, $liftPosY - 4, $liftPosZ + $Z, $setBlock, false);
						}
					}
					foreach ( $insideEntities as $p ) {
						if ( !$p instanceof Player ) {
							$p->teleport($p->getPosition()->add(0, 1, 0));
						}
					}
					++$liftPos->y;
					$movingLift->moving = true;
				} elseif ( $movingLift->movement === self::MOVEMENT_DOWN ) {
					$fillerBlockIdsIndex = 0;
					$airBlock = VanillaBlocks::AIR();
					$glassBlock = VanillaBlocks::GLASS();
					for ( $X = -$offset; $X <= $offset; ++$X ) {
						for ( $Z = -$offset; $Z <= $offset; ++$Z ) {
							$setBlock = (($X === 0 and $Z === 0) ? VanillaBlocks::GOLD() : VanillaBlocks::IRON());
							$world->setBlockAt($liftPosX + $X, $liftPosY, $liftPosZ + $Z, $fillerBlockIds[$fillerBlockIdsIndex++] === BlockTypeIds::GLASS ? $glassBlock : $airBlock, false);
							$world->setBlockAt($liftPosX + $X, $liftPosY - 1, $liftPosZ + $Z, $setBlock, false);
							$world->setBlockAt($liftPosX + $X, $liftPosY - 5, $liftPosZ + $Z, $airBlock, false);
							$world->setBlockAt($liftPosX + $X, $liftPosY - 6, $liftPosZ + $Z, $setBlock, false);
						}
					}
					foreach ( $insideEntities as $p ) {
						if ( !$p instanceof Player ) {
							$p->teleport($p->getPosition()->add(0, -1, 0));
						}
					}
					--$liftPos->y;
					$movingLift->moving = true;
				}
			}
		}
	}

	public function swapBlock ( MovingLift $movingLift, int $h, int $offset ) : bool {
		$liftPos = $movingLift->position;
		$posY = $liftPos->getFloorY();
		$world = $liftPos->getWorld();
		if ( $movingLift->movement === self::MOVEMENT_UP ) {
			if ( $h < 6 or ($posY + 6) > ($world->getMaxY() - 1) ) {
				return false;
			}
			$h = 6;
		} elseif ( $movingLift->movement === self::MOVEMENT_DOWN ) {
			if ( $h > -6 or ($posY - 5 - 6) < $world->getMinY() ) {
				return false;
			}
			$h = -6;
		} else {
			return false;
		}
		$posX = $liftPos->getFloorX();
		$posZ = $liftPos->getFloorZ();
		$minY = $posY + $h - 5;
		$maxY = $posY + $h;
		$fillerBlockIds = [];
		for ( $X = -$offset; $X <= $offset; ++$X ) {
			for ( $Y = $minY; $Y <= $maxY; ++$Y ) {
				for ( $Z = -$offset; $Z <= $offset; ++$Z ) {
					$fillerBlockIds[] = $curFillerBlockId = $world->getBlockAt($posX + $X, $Y, $posZ + $Z, false, false)->getTypeId();
					if ( $curFillerBlockId !== BlockTypeIds::AIR and $curFillerBlockId !== BlockTypeIds::GLASS ) {
						return false;
					}
				}
			}
		}
		foreach ( $movingLift->insideEntities as $p ) {
			$p->teleport($p->getPosition()->add(0, $h, 0));
		}
		$airBlock = VanillaBlocks::AIR();
		$glassBlock = VanillaBlocks::GLASS();
		for ( $X = -$offset; $X <= $offset; ++$X ) {
			for ( $Y = ($posY - 5); $Y <= $posY; ++$Y ) {
				for ( $Z = -$offset; $Z <= $offset; ++$Z ) {
					$world->setBlockAt($posX + $X, $Y, $posZ + $Z, array_shift($fillerBlockIds) === BlockTypeIds::GLASS ? $glassBlock : $airBlock, false);
				}
			}
		}
		for ( $X = -$offset; $X <= $offset; ++$X ) {
			for ( $Z = -$offset; $Z <= $offset; ++$Z ) {
				$setBlock = (($X === 0 and $Z === 0) ? VanillaBlocks::GOLD() : VanillaBlocks::IRON());
				$world->setBlockAt($posX + $X, $posY + $h, $posZ + $Z, $setBlock, false);

				$world->setBlockAt($posX + $X, $posY + $h - 1, $posZ + $Z, $airBlock, false);
				$world->setBlockAt($posX + $X, $posY + $h - 2, $posZ + $Z, $airBlock, false);
				$world->setBlockAt($posX + $X, $posY + $h - 3, $posZ + $Z, $airBlock, false);
				$world->setBlockAt($posX + $X, $posY + $h - 4, $posZ + $Z, $airBlock, false);

				$world->setBlockAt($posX + $X, $posY + $h - 5, $posZ + $Z, $setBlock, false);
			}
		}
		$liftPos->y += $h;

		return true;
	}

	/**
	 * @param array{0:string, 1:int}[] $floorDataList - [[0=>buttonText, 1=> targetY], ...]
	 */
	public function sendForm ( Player $p, array $floorDataList, World $world, Vector3 $liftPos, bool $fastMode ) : void {
		if ( !$this->multiple_floors_mode ) {
			return;
		}

		$n = $p->getName();
		if ( ($this->sendFormCoolDown[$n] ?? 0) > hrtime(true) ) {
			return;
		}
		$this->sendFormCoolDown[$n] = hrtime(true) + 700_000_000;//0.7s

		$data = [
			'type' => 'form',
			'title' => TF::DARK_BLUE . Locale::get($fastMode ? Locale::MENU_TITLE_LIFT_FAST_MODE : Locale::MENU_TITLE_LIFT),
			'content' => TF::YELLOW . Locale::get(Locale::SELECT_FLOOR) . ":\n",
			'buttons' => [],
		];
		foreach ( $floorDataList as $floorData ) {
			$data['buttons'][] = ['text' => $floorData[0]];
		}

		new Form($p, $data, function (Player $p, $data) use ($floorDataList, $world, $liftPos, $fastMode) {
			if ( !$this->multiple_floors_mode ) {
				return;
			}
			if ( !is_int($data) ) {
				return;
			}

			if ( !isset($floorDataList[$data]) ) {
				return;
			}
			if ( $data === 0 or $data === (count($floorDataList) - 1) ) {
				$fastMode = false;
			}
			$hash = self::getLiftHash($world, $liftPos);
			if ( !isset($this->movingLift[$hash]) and $this->isLiftColumn($world, $liftPos) ) {
				$this->movingLift[$hash] = new MovingLift(
					position: Position::fromObject($liftPos, $world),
					movement: $floorDataList[$data][1] > $liftPos->getFloorY() ? self::MOVEMENT_UP : self::MOVEMENT_DOWN,
					waiting: null,
					moving: false,
					playSound: false,
					targetY: $floorDataList[$data][1],
					unset: false,
					liftSize: $this->getLiftSizeByPosition($world, $liftPos),
					fastMode: $fastMode,
				);
			} else {
				$p->sendMessage(TF::RED . '!!! ' . Locale::get(Locale::LIFT_MOVED) . ' !!!');
			}
		});
	}

	/**
	 * @handleCancelled
	 */
	public function tap ( PlayerInteractEvent $e ) {
		if ( $e->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK ) {
			return;
		}
		$p = $e->getPlayer();
		if ( $p->isSneakPressed() ) {
			return;
		}
		$n = $p->getName();
		$b = $e->getBlock();
		$bPos = $b->getPosition();
		$world = $bPos->getWorld();
		$id = $b->getTypeId();
		if ( $id === BlockTypeIds::GOLD ) {
			$liftPos = $bPos->asVector3();
			if ( $bPos->y < $p->getPosition()->y ) {
				$liftPos->y += 5;
			}
			if ( $this->isLiftColumn($world, $liftPos) ) {
				$e->cancel();
				$hash = self::getLiftHash($world, $liftPos);
				$movingLift = ($this->movingLift[$hash] ?? null);
				if ( $movingLift === null ) {
					if ( $this->multiple_floors_mode ) {
						$worldMaxY = $world->getMaxY();
						$worldMinY = $world->getMinY();
						$liftMinY = $worldMinY + 5;

						$floorDataList = [];
						$fastMode = false;
						$liftPosX = $liftPos->getFloorX();
						$liftPosY = $liftPos->getFloorY();
						$liftPosZ = $liftPos->getFloorZ();
						for ( $y = $worldMaxY - 1; $y >= $liftMinY; --$y ) {
							foreach ( self::QUEUE_CHECK_XZ_SIGN as [$offsetX, $offsetZ] ) {
								$signBlock = $world->getBlockAt($liftPosX + $offsetX, $y - 3, $liftPosZ + $offsetZ, false, false);
								if ( $signBlock instanceof BaseSign ) {
									foreach ( [true, false] as $signFrontFace ) {
										$signText = $signBlock->getFaceText($signFrontFace);
										if ( strtolower($signText->getLine(0)) === '[lift]' ) {
											$floorDataList[] = [TF::DARK_BLUE . $signText->getLine(1) . TF::RESET . TF::DARK_BLUE . ' (' . Locale::get(Locale::HEIGHT) . ':' . ($y - 4) . ')' . ($y === $liftPosY ? "\n" . TF::DARK_RED . '[*** ' . Locale::get(Locale::CURRENT_HEIGHT) . ' ***]' : ''), $y];
											if ( !$fastMode and strtolower($signText->getLine(2)) === 'fast' ) {
												$fastMode = true;
											}
											goto nextY;
										}
									}
								}
							}
							nextY:
						}
						if ( count($floorDataList) !== 0 ) {
							array_unshift($floorDataList, [TF::DARK_RED . Locale::get(Locale::HIGHEST) . ' (' . Locale::get(Locale::HEIGHT) . ':' . ($worldMaxY - 5) . ')', $worldMaxY - 1]);
							$floorDataList[] = [TF::DARK_RED . Locale::get(Locale::LOWEST) . ' (' . Locale::get(Locale::HEIGHT) . ':' . ($worldMinY + 1) . ')', $liftMinY];
							$this->sendForm($p, $floorDataList, $world, $liftPos, $fastMode);
							return;
						}
					}
					$movement = ($bPos->y > $p->getPosition()->y ? self::MOVEMENT_UP : self::MOVEMENT_DOWN);
					$this->movingLift[$hash] = new MovingLift(
						position: Position::fromObject($liftPos, $world),
						movement: $movement,
						waiting: null,
						moving: false,
						playSound: false,
						targetY: $movement === self::MOVEMENT_UP ? $world->getMaxY() - 1 : $world->getMinY() + 5,
						unset: false,
						liftSize: $this->getLiftSizeByPosition($world, $liftPos),
						fastMode: false,
					);
				} elseif ( $movingLift->waiting !== null ) {
					$p->sendMessage(TF::YELLOW . '!!! ' . Locale::get(Locale::LIFT_WAITING) . ' !!!');
				} elseif ( isset($movingLift->insideEntities[$p->getId()]) ) {
					$movingLift->movement = self::MOVEMENT_STOP;
					$movingLift->waiting = 40;
					$p->sendMessage(TF::GREEN . '> ' . Locale::get(Locale::STOPPED_LIFT));
				}
			}
		} elseif ( $id === BlockTypeIds::REDSTONE_LAMP ) {
			$cancel = $this->addToQueue($p, $bPos, self::QUEUE_CHECK_XZ_REDSTONE_LAMP);
			if ( $cancel ) {
				$world->broadcastPacketToViewers($bPos, self::createPlaySoundPacket($bPos, 'random.click', 1, 0.6));
				$e->cancel();
			}
		} elseif ( $b instanceof BaseSign and match (true) {
			strtolower($b->getFaceText(true)->getLine(0)) === '[lift]',
			strtolower($b->getFaceText(false)->getLine(0)) === '[lift]',
				=> true,
			default => false,
		} ) {
			$e->cancel();
			$cancel = $this->addToQueue($p, $bPos, self::QUEUE_CHECK_XZ_SIGN);
			if ( $cancel ) {
				$world->broadcastPacketToViewers($bPos, self::createPlaySoundPacket($bPos, 'random.click', 1, 0.6));
			}
		}
	}

	/**
	 * @param int[][] $xzArrayList
	 */
	public function addToQueue ( Player $p, Position $bPos, array $xzArrayList ) : bool {
		$cancel = false;
		$world = $bPos->getWorld();
		$blockY = $bPos->getFloorY();
		$worldMinY = $world->getMinY();
		$worldMaxY = $world->getMaxY();
		if ( $blockY >= ($worldMinY + 2) and $blockY <= ($worldMaxY - 4) ) {
			$blockX = $bPos->getFloorX();
			$blockZ = $bPos->getFloorZ();

			foreach ( $xzArrayList as [$offsetX, $offsetZ] ) {
				$x = $blockX + $offsetX;
				$z = $blockZ + $offsetZ;
				for ( $y = $worldMinY + 5; $y < $worldMaxY; ++$y ) {
					if ( $this->isLiftColumn($world, new Vector3($x, $y, $z)) ) {
						$cancel = true;
						$targetY = $blockY + 3;
						if ( $targetY === $y ) {
							$p->sendMessage(TF::GREEN . '> ' . Locale::get(Locale::LIFT_ARRIVED));
							return $cancel;
						}
						$liftPos = new Position($x, $y, $z, $world);
						$hash = self::getLiftHash($world, $liftPos);

						$movingLift = ($this->movingLift[$hash] ??= new MovingLift(
							position: $liftPos,
							movement: self::MOVEMENT_STOP,
							waiting: null,
							moving: false,
							playSound: false,
							targetY: $y,
							unset: true,
							liftSize: $this->getLiftSizeByPosition($world, $liftPos),
							fastMode: true,
						));
						$movingLift->fastMode = true;

						$movingLift->queue[$targetY] ??= new QueueEntry($bPos->asVector3(), $targetY);

						return $cancel;
					}
				}
			}
		}
		return $cancel;
	}

	public function checkLiftEntity ( World $world, $hash ) : void {
		$movingLift = ($this->movingLift[$hash] ?? null);
		if ( $movingLift === null ) {
			return;
		}

		$insideEntities = [];
		$liftPos = $movingLift->position;
		$offset = match ( $movingLift->getSize() ) {
			5 => 2,
			3 => 1,
			default => 0,
		};
		$minX = $liftPos->getFloorX() - $offset;
		$maxX = $liftPos->getFloorX() + $offset + 1;
		$minY = $liftPos->getFloorY() - 6.5;
		$maxY = $liftPos->getFloorY();
		$minZ = $liftPos->getFloorZ() - $offset;
		$maxZ = $liftPos->getFloorZ() + $offset + 1;
		foreach ( ($this->tp_entity ? $world->getEntities() : $world->getViewersForPosition($liftPos)) as $entity ) {
			$pos = $entity->getPosition();
			if ( (!$entity instanceof Player or $entity->getGamemode() !== GameMode::SPECTATOR) and $pos->x > $minX and $pos->x < $maxX and $pos->z > $minZ and $pos->z < $maxZ and $pos->y > $minY and $pos->y < $maxY ) {
				$insideEntities[$entity->getId()] = $entity;
			}
		}
		$movingLift->insideEntities = $insideEntities;
	}

	public function getLiftSizeByPosition ( World $world, Vector3 $pos ) : int {
		if ( $this->isLift_9($world, $pos) ) {
			if ( $this->isLift_25($world, true, $pos) ) {
				return 5;
			}
			return 3;
		}
		return 1;
	}

	public function isLiftColumnIronBlock ( World $world, Vector3 $pos ) : bool {
		return $this->isLiftColumn($world, $pos, BlockTypeIds::IRON);
	}

	public function isLiftColumn ( World $world, Vector3 $pos, $blockId = BlockTypeIds::GOLD ) : bool {
		$y = $pos->getFloorY();

		if ( $y > ($world->getMaxY() - 1) ) {
			return false;
		}
		$x = $pos->getFloorX();
		$z = $pos->getFloorZ();

		$worldMinY = $world->getMinY();

		foreach ( [
			$blockId,
			BlockTypeIds::AIR,
			BlockTypeIds::AIR,
			BlockTypeIds::AIR,
			BlockTypeIds::AIR,
			$blockId,
		] as $i => $id ) {
			$yToCheck = $y - $i;
			if ( $yToCheck < $worldMinY or $world->getBlockAt($x, $yToCheck, $z, false, false)->getTypeId() !== $id ) {
				return false;
			}
		}
		return true;
	}

	public function isLift_25 ( World $world, bool $islift_9, Vector3 $pos ) : bool {
		if ( !$this->enable5x5 ) {
			return false;
		}
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();

		for ( $X = -2; $X <= 2; ++$X ) {
			for ( $Z = -2; $Z <= 2; ++$Z ) {
				if ( abs($X) === 2 or abs($Z) === 2 ) {
					if ( !$this->isLiftColumnIronBlock($world, new Vector3($x + $X, $y, $z + $Z)) ) {
						return false;
					}
				}
			}
		}
		return $islift_9 or $this->isLift_9($world, $pos);
	}

	public function isLift_9 ( World $world, Vector3 $pos ) : bool {
		if ( !$this->enable3x3 ) {
			return false;
		}
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();

		for ( $X = -1; $X <= 1; ++$X ) {
			for ( $Z = -1; $Z <= 1; ++$Z ) {
				if ( $X !== 0 or $Z !== 0 ) {
					if ( !$this->isLiftColumnIronBlock($world, new Vector3($x + $X, $y, $z + $Z)) ) {
						return false;
					}
				} else {
					if ( !$this->isLiftColumn($world, new Vector3($x, $y, $z)) ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	public static function getLiftHash ( World $world, Vector3 $pos ) : string {
		return pack('NJ', $world->getId(), ($pos->getFloorX() << 32) | ($pos->getFloorZ() & 0xffffffff));
	}

	public function pvp ( EntityDamageEvent $e ) {
		$cause = $e->getCause();
		if ( $cause === EntityDamageEvent::CAUSE_FALL or $cause === EntityDamageEvent::CAUSE_SUFFOCATION ) {
			$entityId = $e->getEntity()->getId();
			foreach ( $this->movingLift as $movingLift ) {
				if ( isset($movingLift->insideEntities[$entityId]) ) {
					$e->cancel();
					return;
				}
			}
		}
	}

}

class Form implements PMForm {
	protected array $formData = [];
	protected ?\Closure $closure = null;

	public function __construct ( ?Player $p, array $formData, ?\Closure $closure = null ) {
		$this->formData = $formData;
		$this->closure = $closure;
		if ( $p !== null ) {
			$p->sendForm($this);
		}
	}

	public function handleResponse ( Player $player, $data ) : void {
		if ( $this->closure !== null ) {
			($this->closure)($player, $data);
		}
	}

	public function jsonSerialize () : mixed {
		return $this->formData;
	}

}

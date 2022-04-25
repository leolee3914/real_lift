<?php

declare(strict_types=1);

namespace real_lift;

use pocketmine\block\BaseSign;
use pocketmine\block\BlockLegacyIds;
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
use function count;

class Main extends PluginBase implements Listener {

	const MOVEMENT_UP = 0;
	const MOVEMENT_DOWN = 1;
	const MOVEMENT_STOP = 2;

	const QUEUE_CHECK_XZ_REDSTONE_LAMP = [
		[1,0],[0,1],[-1,0],[0,-1],
	];
	const QUEUE_CHECK_XZ_SIGN = [
		[1,1],[-1,1],[1,-1],[-1,-1],
		[2,2],[-2,2],[2,-2],[-2,-2],
		[3,3],[-3,3],[3,-3],[-3,-3],
	];

	private static self $instance;

	public static function getInstance () : self {
		return self::$instance;
	}

	public bool $multiple_floors_mode, $enable3x3, $enable5x5, $tp_entity;

	/** @var array<string, MovingLift> */
	public array $movingLift = [];

	public array $floorlistliftpos = [];

	public array $sendformtime = [];

	public function onEnable () : void {
		self::$instance = $this;

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		@mkdir($this->getDataFolder());
		$config = new Config($this->getDataFolder() . 'config.yml', Config::YAML, []);
		if ( !isset($config->multiple_floors_mode) ) {
			$config->multiple_floors_mode = true;
			$config->save();
		}
		if ( !isset($config->enable3x3) ) {
			$config->enable3x3 = true;
			$config->save();
		}
		if ( !isset($config->enable5x5) ) {
			$config->enable5x5 = false;
			$config->save();
		}
		if ( !isset($config->tp_entity) ) {
			$config->tp_entity = true;
			$config->save();
		}
		$this->multiple_floors_mode = (bool) $config->multiple_floors_mode;
		$this->enable3x3 = (bool) $config->enable3x3;
		$this->enable5x5 = (bool) $config->enable5x5;
		$this->tp_entity = (bool) $config->tp_entity;

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(\Closure::fromCallable([$this, 'move_lift'])), 1);
	}

	public function pq ( PlayerQuitEvent $e ) {
		$p = $e->getPlayer();
		$n = $p->getName();

		unset($this->floorlistliftpos[$n]);
		unset($this->sendformtime[$n]);
	}

	public static function createPlaySoundPacket ( Vector3 $v3, string $sound, float $vol = 1.0, float $pitch = 1.0 ) {
		$pk = new PlaySoundPacket();
		$pk->soundName = $sound;
		$pk->x = $v3->x;
		$pk->y = $v3->y;
		$pk->z = $v3->z;
		$pk->volume = $vol;
		$pk->pitch = $pitch;

		return $pk;
	}

	public static function createParticlePacket ( Vector3 $pos, string $pname ) {
		$pk = new SpawnParticleEffectPacket();
		$pk->position = $pos;
		$pk->particleName = $pname;
		$pk->molangVariablesJson = '';

		return $pk;
	}

	public function move_lift () {
		foreach ( $this->movingLift as $hash=>$movingLift ) {
			$pos = $movingLift->position;
			$world = $pos->getWorld();
			if ( !$world->isLoaded() or !$this->islift($world, $pos) ) {
				unset($this->movingLift[$hash]);
				continue;
			}

			$issetqueue = (count($movingLift->queue) > 0);
			if ( $issetqueue ) {
				foreach ( $movingLift->queue as $queueEntry ) {
					if ( --$queueEntry->buttonBlinkTimer <= 0 ) {
						$queueEntryPos = $queueEntry->position;

						$block = $world->getBlockAt($queueEntryPos->x,$queueEntryPos->y,$queueEntryPos->z, false, false);
						if ( $block instanceof BaseSign ) {
							$world->broadcastPacketToViewers($queueEntryPos, self::createParticlePacket($queueEntryPos->add(0.5,0.5,0.5), 'minecraft:redstone_ore_dust_particle'));
						} else {
							switch ( $block->getId() ) {
								case BlockLegacyIds::REDSTONE_LAMP;
									$world->setBlockAt($queueEntryPos->x,$queueEntryPos->y,$queueEntryPos->z, VanillaBlocks::REDSTONE_LAMP()->setPowered(true), false);
									break;
								case BlockLegacyIds::LIT_REDSTONE_LAMP;
									$world->setBlockAt($queueEntryPos->x,$queueEntryPos->y,$queueEntryPos->z, VanillaBlocks::REDSTONE_LAMP(), false);
									break;
							}
						}
						$queueEntry->buttonBlinkTimer = 6;
					}
				}
			}

			if ( $movingLift->unset ) {
				if ( $issetqueue ) {
					$first = true;
					foreach ( $movingLift->queue as $queueY=>$queueEntry ) {
						if ( $first and $movingLift->hasQueue ) {
							$queueEntryPos = $queueEntry->position;

							$first = false;
							if ( $world->getBlockAt($queueEntryPos->x,$queueEntryPos->y,$queueEntryPos->z, false, false)->getId() === BlockLegacyIds::LIT_REDSTONE_LAMP ) {
								$world->setBlockAt($queueEntryPos->x,$queueEntryPos->y,$queueEntryPos->z, VanillaBlocks::REDSTONE_LAMP(), false);
							}
							unset($movingLift->queue[$queueY]);
						} else {
							if ( $pos->y > $queueEntry->getTargetY() ) {
								$movingLift->movement = self::MOVEMENT_DOWN;
								$movingLift->waiting = null;
								$movingLift->targetY = $queueEntry->getTargetY();
								$movingLift->unset = false;
								$movingLift->hasQueue = true;
							} elseif ( $pos->y < $queueEntry->getTargetY() ) {
								$movingLift->movement = self::MOVEMENT_UP;
								$movingLift->waiting = null;
								$movingLift->targetY = $queueEntry->getTargetY();
								$movingLift->unset = false;
								$movingLift->hasQueue = true;
							}
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
					$world->broadcastPacketToViewers($pos, self::createPlaySoundPacket($pos, 'random.orb', 1, 2));
				}
				continue;
			}
			$this->liftcheckplayer($world, $hash);
			$insideEntities = $movingLift->insideEntities;
			foreach ( $insideEntities as $entity ) {
				$entity->resetFallDistance();
			}
			$canmove = true;
			if ( $movingLift->movement === self::MOVEMENT_UP ) {
				foreach ( $insideEntities as $p ) {
					if ( $p instanceof Player ) {
						$p->setMotion(new Vector3(0, 0.8, 0));
						if ( $p->getPosition()->y < ($pos->y-2.4) ) {
							$canmove = false;
						}
					}
				}
			} elseif ( $movingLift->movement === self::MOVEMENT_DOWN ) {
				foreach ( $insideEntities as $p ) {
					if ( $p instanceof Player ) {
						$p->setMotion(new Vector3(0, -0.4, 0));
						if ( $p->getPosition()->y > ($pos->y-3) ) {
							$canmove = false;
						}
					}
				}
			}
			switch ( $movingLift->getLiftSize() ) {
				case 5;
					$addmin = -2;
					$addmax = 2;
					break;
				case 3;
					$addmin = -1;
					$addmax = 1;
					break;
				default;
					$addmin = $addmax = 0;
					break;
			}

			if ( $issetqueue and !$movingLift->fastMode ) {
				$movingLift->fastMode = true;
			}
			if ( $movingLift->fastMode ) {
				$swapBlock = $this->swapBlock($movingLift, $movingLift->movement, $movingLift->targetY - $pos->y, $insideEntities, $addmin, $addmax);
				if ( $swapBlock ) {
					$movingLift->moving = true;
					continue;
				} else {
					$movingLift->fastMode = false;
				}
			}
			$fillerIds = [];
			$stop = false;
			if ( $movingLift->movement === self::MOVEMENT_UP and $canmove ) {
				if ( ($pos->y+1) >= $world->getMaxY() or $pos->y === $movingLift->targetY ) {
					$stop = true;
				}
				for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
					for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
						$fillerIds[] = $curFillerId = $world->getBlockAt($pos->x+$addx, $pos->y+1, $pos->z+$addz, false, false)->getId();
						if ( $stop or ($curFillerId !== BlockLegacyIds::AIR and $curFillerId !== BlockLegacyIds::GLASS) ) {
							$stop = true;
							break 2;
						}
					}
				}
			} elseif ( $movingLift->movement === self::MOVEMENT_DOWN and $canmove ) {
				if ( $pos->y <= 5 or $pos->y === $movingLift->targetY ) {
					$stop = true;
				}
				for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
					for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
						$fillerIds[] = $curFillerId = $world->getBlockAt($pos->x+$addx, $pos->y-6, $pos->z+$addz, false, false)->getId();
						if ( $stop or ($curFillerId !== BlockLegacyIds::AIR and $curFillerId !== BlockLegacyIds::GLASS) ) {
							$stop = true;
							break 2;
						}
					}
				}
			} else {
				$canmove = false;
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
			if ( $canmove ) {
				$liftsize = $this->getliftsize($world, $pos);
				if ( $liftsize !== $movingLift->getLiftSize() ) {
					unset($this->movingLift[$hash]);
					continue;
				}
				if ( $movingLift->movement === self::MOVEMENT_UP ) {
					$ii = 0;
					$airBlock = VanillaBlocks::AIR();
					$glassBlock = VanillaBlocks::GLASS();
					for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
						for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
							$setBlock = ($addx === 0 && $addz === 0 ? VanillaBlocks::GOLD() : VanillaBlocks::IRON());
							$world->setBlockAt($pos->x+$addx, $pos->y, $pos->z+$addz, $airBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y+1, $pos->z+$addz, $setBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y-5, $pos->z+$addz, $fillerIds[$ii++] === BlockLegacyIds::GLASS ? $glassBlock : $airBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y-4, $pos->z+$addz, $setBlock, false);
						}
					}
					foreach ( $insideEntities as $p ) {
						if ( !$p instanceof Player ) {
							$p->teleport($p->getPosition()->add(0, 1, 0));
						}
					}
					++$pos->y;
					$movingLift->moving = true;
				} elseif ( $movingLift->movement === self::MOVEMENT_DOWN ) {
					$ii = 0;
					$airBlock = VanillaBlocks::AIR();
					$glassBlock = VanillaBlocks::GLASS();
					for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
						for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
							$setBlock = ($addx === 0 && $addz === 0 ? VanillaBlocks::GOLD() : VanillaBlocks::IRON());
							$world->setBlockAt($pos->x+$addx, $pos->y, $pos->z+$addz, $fillerIds[$ii++] === BlockLegacyIds::GLASS ? $glassBlock : $airBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y-1, $pos->z+$addz, $setBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y-5, $pos->z+$addz, $airBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y-6, $pos->z+$addz, $setBlock, false);
						}
					}
					foreach ( $insideEntities as $p ) {
						if ( !$p instanceof Player ) {
							$p->teleport($p->getPosition()->add(0, -1, 0));
						}
					}
					--$pos->y;
					$movingLift->moving = true;
				}
			}
		}
	}

	public function swapBlock ( MovingLift $movingLift, $updown, $h, $pls, $addmin=0, $addmax=0 ) {
		$pos = $movingLift->position;
		$world = $pos->getWorld();
		if ( $updown === self::MOVEMENT_UP ) {
			if ( $h < 6 or ($pos->y+6) >= $world->getMaxY() ) {
				return false;
			}
			$h = 6;
		} elseif ( $updown === self::MOVEMENT_DOWN ) {
			if ( $h > -6 or ($pos->y-5) <= 5 ) {
				return false;
			}
			$h = -6;
		} else {
			return false;
		}
		$mixy = $pos->y+$h-5;
		$maxy = $pos->y+$h;
		$fillerIds = [];
		for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
			for ( $addy=$mixy;$addy<=$maxy;++$addy ) {
				for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
					$fillerIds[] = $curFillerId = $world->getBlockAt($pos->x+$addx, $addy, $pos->z+$addz, false, false)->getId();
					if ( $curFillerId !== BlockLegacyIds::AIR and $curFillerId !== BlockLegacyIds::GLASS ) {
						return false;
					}
				}
			}
		}
		foreach ( $pls as $p ) {
			$p->teleport($p->getPosition()->add(0, $h, 0));
		}
		$airBlock = VanillaBlocks::AIR();
		$glassBlock = VanillaBlocks::GLASS();
		for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
			for ( $addy=($pos->y-5);$addy<=$pos->y;++$addy ) {
				for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
					$world->setBlockAt($pos->x+$addx, $addy, $pos->z+$addz, array_shift($fillerIds) === BlockLegacyIds::GLASS ? $glassBlock : $airBlock, false);
				}
			}
		}
		for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
			for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
				$setBlock = ($addx === 0 && $addz === 0 ? VanillaBlocks::GOLD() : VanillaBlocks::IRON());
				$world->setBlockAt($pos->x+$addx, $pos->y+$h, $pos->z+$addz, $setBlock, false);

				$world->setBlockAt($pos->x+$addx, $pos->y+$h-1, $pos->z+$addz, $airBlock, false);
				$world->setBlockAt($pos->x+$addx, $pos->y+$h-2, $pos->z+$addz, $airBlock, false);
				$world->setBlockAt($pos->x+$addx, $pos->y+$h-3, $pos->z+$addz, $airBlock, false);
				$world->setBlockAt($pos->x+$addx, $pos->y+$h-4, $pos->z+$addz, $airBlock, false);

				$world->setBlockAt($pos->x+$addx, $pos->y+$h-5, $pos->z+$addz, $setBlock, false);
			}
		}
		$pos->y += $h;
		return true;
	}

	public function sendForm ( Player $p, array $floorList ) {
		if ( !$this->multiple_floors_mode ) {
			return;
		}

		$n = $p->getName();
		if ( isset($this->sendformtime[$n]) and $this->sendformtime[$n] > microtime(true) ) {
			return;
		}
		$this->sendformtime[$n] = microtime(true)+0.7;

		$data = [
			'type'=>'form',
			'title'=>TF::DARK_BLUE . '升降機' . ($this->floorlistliftpos[$n][3]===true ? ' (快速模式)' : ''),
			'content'=>TF::YELLOW . "請選擇樓層:\n",
			'buttons'=>[],
		];
		foreach ( $floorList as $floor ) {
			$data['buttons'][] = ['text'=>$floor[0]];
		}

		new Form($p, $data, function (Player $p, $data) use ($floorList) {
			if ( !$this->multiple_floors_mode ) {
				return;
			}
			if ( $data === null ) {
				return;
			}
			$n = $p->getName();

			$data = (int) $data;
			if ( !isset($floorList[$data]) ) {
				return;
			}
			$pos = $this->floorlistliftpos[$n];
			$world = $pos[0];
			$v3 = $pos[1];
			$fastMode = $pos[3];
			if ( $data === 0 or $data === (count($floorList)-1) ) {
				$fastMode = false;
			}
			$hash = self::getLiftHash($world, $v3);
			if ( !isset($this->movingLift[$hash]) and $this->islift($world, $v3) ) {
				$this->movingLift[$hash] = new MovingLift(
					position: Position::fromObject($v3, $world),
					movement: $floorList[$data][1] > $v3->y ? self::MOVEMENT_UP : self::MOVEMENT_DOWN,
					waiting: null,
					moving: false,
					playSound: false,
					targetY: $floorList[$data][1],
					unset: false,
					liftSize: $this->getliftsize($world, $v3),
					fastMode: $fastMode,
				);
			} else {
				$p->sendMessage(TF::RED . '!!! 你不在該升降機中或升降機已經移動 !!!');
			}
			unset($this->floorlistliftpos[$n]);
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
		if ( $p->isSneaking() ) {
			return;
		}
		$n = $p->getName();
		$b = $e->getBlock();
		$b_pos = $b->getPosition();
		$world = $b_pos->getWorld();
		$id = $b->getId();
		if ( $id === BlockLegacyIds::GOLD_BLOCK ) {
			$v3 = $b_pos->asVector3();
			if ( $b_pos->y < $p->getPosition()->y ) {
				$v3->y += 5;
			}
			if ( $this->islift($world, $v3) ) {
				$e->cancel();
				$hash = self::getLiftHash($world, $v3);
				$movingLift = ($this->movingLift[$hash] ?? null);
				if ( $movingLift === null ) {
					if ( $this->multiple_floors_mode ) {
						$lvh = $world->getMaxY();
						$floorList = [];
						$fast_mode = false;
						for ( $y=$lvh-1;$y>=5;--$y ) {
							foreach ( self::QUEUE_CHECK_XZ_SIGN as $xz ) {
								$x = $v3->x+$xz[0];
								$z = $v3->z+$xz[1];
								$yy = $y-3;
								$signBlock = $world->getBlockAt($x, $yy, $z, false, false);
								if ( $signBlock instanceof BaseSign ) {
									$signText = $signBlock->getText();
									if ( strtolower($signText->getLine(0)) === '[lift]' ) {
										$floorList[] = [TF::DARK_BLUE . $signText->getLine(1) . TF::RESET . TF::DARK_BLUE . ' (高度:' . ($y-4) . ')' . ($y === $v3->y ? "\n" . TF::DARK_RED . '[*** 目前高度 ***]' : ''), $y];
										if ( !$fast_mode and strtolower($signText->getLine(2)) === 'fast' ) {
											$fast_mode = true;
										}
										goto nextY;
									}
								}
							}
							nextY:
						}
						if ( count($floorList) !== 0 ) {
							array_unshift($floorList, [TF::DARK_RED . '最高層 (高度:' . ($lvh-5) . ')', $lvh-1]);
							$floorList[] = [TF::DARK_RED . '最低層 (高度:1)', 5];
							$this->floorlistliftpos[$n] = [$world, $v3, $p, $fast_mode];
							$this->sendForm($p, $floorList);
							return;
						}
					}
					$this->movingLift[$hash] = new MovingLift(
						position: Position::fromObject($v3, $world),
						movement: $b_pos->y > $p->getPosition()->y ? self::MOVEMENT_UP : self::MOVEMENT_DOWN,
						waiting: null,
						moving: false,
						playSound: false,
						targetY: null,
						unset: false,
						liftSize: $this->getliftsize($world, $v3),
						fastMode: false,
					);
				} elseif ( $movingLift->waiting !== null ) {
					$p->sendMessage(TF::YELLOW . '!!! 升降機稍作停留，請等候數秒鐘 !!!');
				} elseif ( isset($movingLift->insideEntities[$p->getId()]) ) {
					$movingLift->movement = self::MOVEMENT_STOP;
					$movingLift->waiting = 40;
					$p->sendMessage(TF::GREEN . '> 已停止升降機');
				}
			}
		} elseif ( $id === BlockLegacyIds::REDSTONE_LAMP or $id === BlockLegacyIds::LIT_REDSTONE_LAMP ) {
			if ( !$p->isSneaking() ) {
				$cancel = $this->checkqueue($p, $b_pos, self::QUEUE_CHECK_XZ_REDSTONE_LAMP);
				if ( $cancel ) {
					$world->broadcastPacketToViewers($b_pos, self::createPlaySoundPacket($b_pos, 'random.click', 1, 0.6));
					$e->cancel();
				}
			}
		} elseif ( $b instanceof BaseSign and strtolower($b->getText()->getLine(0)) === '[lift]' ) {
			$e->cancel();
			$cancel = $this->checkqueue($p, $b_pos, self::QUEUE_CHECK_XZ_SIGN);
			if ( $cancel ) {
				$world->broadcastPacketToViewers($b_pos, self::createPlaySoundPacket($b_pos, 'random.click', 1, 0.6));
			}
		}
	}

	public function checkqueue ( Player $p, Position $b, array $checkxz ) {
		$cancel = false;
		$world = $b->getWorld();
		$lvh = $world->getMaxY();
		if ( $b->y >= 2 and $b->y <= ($lvh-4) ) {
			foreach ( $checkxz as $xz ) {
				$x = $b->x+$xz[0];
				$z = $b->z+$xz[1];
				for ( $y=5;$y<$lvh;++$y ) {
					if ( $this->islift($world, $x, $y, $z) ) {
						$cancel = true;
						$targetY = $b->y+3;
						if ( $targetY === $y ) {
							$p->sendMessage(TF::GREEN . '> 升降機已經到達');
							return $cancel;
						}
						$v3 = new Vector3($x, $y, $z);
						$hash = self::getLiftHash($world, $v3);

						$movingLift = ($this->movingLift[$hash] ??= new MovingLift(
							position: Position::fromObject($v3, $world),
							movement: self::MOVEMENT_STOP,
							waiting: null,
							moving: false,
							playSound: false,
							targetY: null,
							unset: true,
							liftSize: $this->getliftsize($world, $v3),
							fastMode: true,
						));
						$movingLift->fastMode = true;

						$movingLift->queue[$targetY] ??= new QueueEntry($b, $targetY);

						return $cancel;
					}
				}
			}
		}
		return $cancel;
	}

	public function liftcheckplayer ( World $world, $hash ) {
		$movingLift = ($this->movingLift[$hash] ?? null);
		if ( $movingLift === null ) {
			return;
		}

		$insideEntities = [];
		$v3 = $movingLift->position;
		switch ( $movingLift->getLiftSize() ) {
			case 5;
				$addmin = -2;
				$addmax = 2;
				break;
			case 3;
				$addmin = -1;
				$addmax = 1;
				break;
			default;
				$addmin = $addmax = 0;
				break;
		}
		$minx = $v3->x+$addmin;
		$maxx = $v3->x+$addmax+1;
		$miny = $v3->y-6.5;
		$maxy = $v3->y;
		$minz = $v3->z+$addmin;
		$maxz = $v3->z+$addmax+1;
		foreach ( ($this->tp_entity ? $world->getEntities() : $world->getViewersForPosition($v3)) as $pl ) {
			$ispl = ($pl instanceof Player);
			$pos = $pl->getPosition();
			if ( (!$ispl or !$pl->getGamemode()->equals(GameMode::SPECTATOR())) and $pos->x > $minx and $pos->x < $maxx and $pos->z > $minz and $pos->z < $maxz and $pos->y > $miny and $pos->y < $maxy ) {
				$insideEntities[$pl->getId()] = $pl;
			}
		}
		$movingLift->insideEntities = $insideEntities;
	}

	public function getliftsize ( World $world, Vector3 $pos ) {
		if ( $this->islift2_9($world, $pos) ) {
			if ( $this->islift2_25($world, true, $pos) ) {
				return 5;
			}
			return 3;
		}
		return 1;
	}

	public function islift2 ( World $world, $x, $y=0, $z=0 ) {
		if ( $x instanceof Vector3 ) {
			$y = $x->y;
			$z = $x->z;

			$x = $x->x;
		}
		return $this->islift($world, $x, $y, $z, BlockLegacyIds::IRON_BLOCK);
	}

	public function islift ( World $world, $x, $y=0, $z=0, $bid=BlockLegacyIds::GOLD_BLOCK ) {
		if ( $x instanceof Vector3 ) {
			$y = $x->y;
			$z = $x->z;

			$x = $x->x;
		}
		$idlist = [$bid, 0, 0, 0, 0, $bid];
		foreach ( $idlist as $i=>$id ) {
			$yy = $y-$i;
			if ( $yy < 0 or $world->getBlockAt($x, $yy, $z, false, false)->getId() !== $id ) {
				return false;
			}
		}
		return true;
	}

	public function islift2_25 ( World $world, $islift_9, $x, $y=0, $z=0 ) {
		if ( !$this->enable5x5 ) {
			return false;
		}
		if ( $x instanceof Vector3 ) {
			$y = $x->y;
			$z = $x->z;

			$x = $x->x;
		}
		for ( $addx=-2;$addx<=2;++$addx ) {
			for ( $addz=-2;$addz<=2;++$addz ) {
				if ( abs($addx) === 2 or abs($addz) === 2 ) {
					if ( !$this->islift2($world, $x+$addx, $y, $z+$addz) ) {
						return false;
					}
				}
			}
		}
		return ( $islift_9 or $this->islift2_9($world, $x, $y, $z) );
	}

	public function islift2_9 ( World $world, $x, $y=0, $z=0 ) {
		if ( !$this->enable3x3 ) {
			return false;
		}
		if ( $x instanceof Vector3 ) {
			$y = $x->y;
			$z = $x->z;

			$x = $x->x;
		}
		for ( $addx=-1;$addx<=1;++$addx ) {
			for ( $addz=-1;$addz<=1;++$addz ) {
				if ( $addx !== 0 or $addz !== 0 ) {
					if ( !$this->islift2($world, $x+$addx, $y, $z+$addz) ) {
						return false;
					}
				} else {
					if ( !$this->islift($world, $x, $y, $z) ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	public static function getLiftHash ( World $world, Vector3 $v3 ) : string {
		return $world->getFolderName() . pack('J', (((int) $v3->x) << 32) | (((int) $v3->z) & 0xffffffff));
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

	public function jsonSerialize () {
		return $this->formData;
	}

}


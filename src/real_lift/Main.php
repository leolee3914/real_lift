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

	const MOVE_UP = 0;
	const MOVE_DOWN = 1;
	const MOVE_STOP = 2;

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

	public array $movinglift = [];

	public array $queue = [];

	public array $floorlist = [];
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

		unset($this->floorlist[$n]);
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
		foreach ( $this->movinglift as $hash=>&$data ) {
			/**
			 * hash => [
			 *  0=>(Position),
			 *  1=>(Player|Entity[])  entityID => Player|Entity,
			 *  2=>(int const)up|down|stop,
			 *  3=>(bool)waiting,
			 *  4=>(bool)is moved,
			 *  5=>(bool)playsound,
			 *  6=>(int)target-y,
			 *  7=>(bool)unset,
			 *  8=>(bool)hasQueue,
			 *  9=>int lift_size: 1/3/5,
			 *  10=>(bool)fast_mode
			 * ]
			 */
			$pos = $data[0];
			$world = $pos->getWorld();
			if ( !$world->isLoaded() or !$this->islift($world, $pos) ) {
				unset($this->queue[$hash]);
				unset($this->movinglift[$hash]);
				continue;
			}

			$issetqueue = (isset($this->queue[$hash]) and count($this->queue[$hash]) !== 0);
			if ( $issetqueue ) {
				foreach ( $this->queue[$hash] as &$dt2 ) {
					if ( --$dt2[2] <= 0 ) {
						$block = $world->getBlockAt($dt2[0]->x,$dt2[0]->y,$dt2[0]->z, false, false);
						if ( $block instanceof BaseSign ) {
							$world->broadcastPacketToViewers($dt2[0], self::createParticlePacket($dt2[0]->add(0.5,0.5,0.5), 'minecraft:redstone_ore_dust_particle'));
						} else {
							switch ( $block->getId() ) {
								case BlockLegacyIds::REDSTONE_LAMP;
									$world->setBlockAt($dt2[0]->x,$dt2[0]->y,$dt2[0]->z, VanillaBlocks::REDSTONE_LAMP()->setPowered(true), false);
									break;
								case BlockLegacyIds::LIT_REDSTONE_LAMP;
									$world->setBlockAt($dt2[0]->x,$dt2[0]->y,$dt2[0]->z, VanillaBlocks::REDSTONE_LAMP(), false);
									break;
							}
						}
						$dt2[2] = 6;
					}
				}
			}

			if ( $data[7] ) {
				if ( $issetqueue ) {
					$first = true;
					foreach ( $this->queue[$hash] as $lift_y=>$dt ) {
						if ( $first === true and $data[8] === true ) {
							$first = false;
							if ( $world->getBlockAt($dt[0]->x,$dt[0]->y,$dt[0]->z, false, false)->getId() === BlockLegacyIds::LIT_REDSTONE_LAMP ) {
								$world->setBlockAt($dt[0]->x,$dt[0]->y,$dt[0]->z, VanillaBlocks::REDSTONE_LAMP(), false);
							}
							unset($this->queue[$hash][$lift_y]);
						} else {
							if ( $pos->y > $dt[1] ) {
								$data[2] = self::MOVE_DOWN;
								$data[3] = false;
								$data[6] = $dt[1];
								$data[7] = false;
								$data[8] = true;
							} elseif ( $pos->y < $dt[1] ) {
								$data[2] = self::MOVE_UP;
								$data[3] = false;
								$data[6] = $dt[1];
								$data[7] = false;
								$data[8] = true;
							}
							$data[10] = true;
							break;
						}
					}
				}
				if ( $data[7] ) {
					unset($this->queue[$hash]);
					unset($this->movinglift[$hash]);
				}
				continue;
			}
			if ( $data[3] !== false ) {
				if ( --$data[3] <= 0 ) {
					$data[7] = true;
				}
				if ( $data[3] === 20 and $data[5] ) {
					$world->broadcastPacketToViewers($pos, self::createPlaySoundPacket($pos, 'random.orb', 1, 2));
				}
				continue;
			}
			$this->liftcheckplayer($world, $hash);
			$pls = $data[1];
			foreach ( $pls as $entity ) {
				$entity->resetFallDistance();
			}
			$canmove = true;
			if ( $data[2] === self::MOVE_UP ) {
				foreach ( $pls as $p ) {
					if ( $p instanceof Player ) {
						$p->setMotion(new Vector3(0, 0.8, 0));
						if ( $p->getPosition()->y < ($pos->y-2.4) ) {
							$canmove = false;
						}
					}
				}
			} elseif ( $data[2] === self::MOVE_DOWN ) {
				foreach ( $pls as $p ) {
					if ( $p instanceof Player ) {
						$p->setMotion(new Vector3(0, -0.4, 0));
						if ( $p->getPosition()->y > ($pos->y-3) ) {
							$canmove = false;
						}
					}
				}
			}
			if ( $data[9] === 5 ) {
				$addmin = -2;
				$addmax = 2;
			} elseif ( $data[9] === 3 ) {
				$addmin = -1;
				$addmax = 1;
			} else {
				$addmin = $addmax = 0;
			}
			if ( $issetqueue and $data[10] === false ) {
				$data[10] = true;
			}
			if ( $data[10] === true ) {
				$switchblock = $this->switchblock($data, $data[2], $data[6]-$pos->y, $pls, $addmin, $addmax);
				if ( $switchblock ) {
					$data[4] = true;
					continue;
				} else {
					$data[10] = 0;
				}
			}
			$airid = [];
			$stop = false;
			if ( $data[2] === self::MOVE_UP and $canmove ) {
				if ( ($pos->y+1) >= $world->getMaxY() or $pos->y === $data[6] ) {
					$stop = true;
				}
				for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
					for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
						$airid[] = $airid2 = $world->getBlockAt($pos->x+$addx, $pos->y+1, $pos->z+$addz, false, false)->getId();
						if ( $stop === true or ($airid2 !== BlockLegacyIds::AIR and $airid2 !== BlockLegacyIds::GLASS) ) {
							$stop = true;
							break 2;
						}
					}
				}
			} elseif ( $data[2] === self::MOVE_DOWN and $canmove ) {
				if ( $pos->y <= 5 or $pos->y === $data[6] ) {
					$stop = true;
				}
				for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
					for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
						$airid[] = $airid2 = $world->getBlockAt($pos->x+$addx, $pos->y-6, $pos->z+$addz, false, false)->getId();
						if ( $stop === true or ($airid2 !== BlockLegacyIds::AIR and $airid2 !== BlockLegacyIds::GLASS) ) {
							$stop = true;
							break 2;
						}
					}
				}
			} else {
				$canmove = false;
			}
			if ( $stop ) {
				if ( $data[4] === false ) {
					$data[7] = true;
					continue;
				}
				$data[2] = self::MOVE_STOP;
				$data[3] = 40;
				$data[5] = true;
				continue;
			}
			if ( $canmove ) {
				$liftsize = $this->getliftsize($world, $pos);
				if ( $liftsize !== $data[9] ) {
					unset($this->queue[$hash]);
					unset($this->movinglift[$hash]);
					continue;
				}
				if ( $data[2] === self::MOVE_UP ) {
					$ii = 0;
					$airBlock = VanillaBlocks::AIR();
					$glassBlock = VanillaBlocks::GLASS();
					for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
						for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
							$setBlock = ($addx === 0 && $addz === 0 ? VanillaBlocks::GOLD() : VanillaBlocks::IRON());
							$world->setBlockAt($pos->x+$addx, $pos->y, $pos->z+$addz, $airBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y+1, $pos->z+$addz, $setBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y-5, $pos->z+$addz, $airid[$ii++] === BlockLegacyIds::GLASS ? $glassBlock : $airBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y-4, $pos->z+$addz, $setBlock, false);
						}
					}
					foreach ( $pls as $p ) {
						if ( !$p instanceof Player ) {
							$p->teleport($p->getPosition()->add(0, 1, 0));
						}
					}
					++$pos->y;
					$data[4] = true;
				} elseif ( $data[2] === self::MOVE_DOWN ) {
					$ii = 0;
					$airBlock = VanillaBlocks::AIR();
					$glassBlock = VanillaBlocks::GLASS();
					for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
						for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
							$setBlock = ($addx === 0 && $addz === 0 ? VanillaBlocks::GOLD() : VanillaBlocks::IRON());
							$world->setBlockAt($pos->x+$addx, $pos->y, $pos->z+$addz, $airid[$ii++] === BlockLegacyIds::GLASS ? $glassBlock : $airBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y-1, $pos->z+$addz, $setBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y-5, $pos->z+$addz, $airBlock, false);
							$world->setBlockAt($pos->x+$addx, $pos->y-6, $pos->z+$addz, $setBlock, false);
						}
					}
					foreach ( $pls as $p ) {
						if ( !$p instanceof Player ) {
							$p->teleport($p->getPosition()->add(0, -1, 0));
						}
					}
					--$pos->y;
					$data[4] = true;
				}
			}
		}
	}

	public function switchblock ( &$data, $updown, $h, $pls, $addmin=0, $addmax=0 ) {
		$pos = $data[0];
		$world = $pos->getWorld();
		if ( $updown === self::MOVE_UP ) {
			if ( $h < 6 or ($pos->y+6) >= $world->getMaxY() ) {
				return false;
			}
			$h = 6;
		} elseif ( $updown === self::MOVE_DOWN ) {
			if ( $h > -6 or ($pos->y-5) <= 5 ) {
				return false;
			}
			$h = -6;
		} else {
			return false;
		}
		$mixy = $pos->y+$h-5;
		$maxy = $pos->y+$h;
		$airid = [];
		for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
			for ( $addy=$mixy;$addy<=$maxy;++$addy ) {
				for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
					$airid[] = $airid2 = $world->getBlockAt($pos->x+$addx, $addy, $pos->z+$addz, false, false)->getId();
					if ( $airid2 !== BlockLegacyIds::AIR and $airid2 !== BlockLegacyIds::GLASS ) {
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
					$world->setBlockAt($pos->x+$addx, $addy, $pos->z+$addz, array_shift($airid) === BlockLegacyIds::GLASS ? $glassBlock : $airBlock, false);
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

	public function sendform ( Player $p, int $formId ) {
		$n = $p->getName();
		if ( isset($this->sendformtime[$n]) and $this->sendformtime[$n] > microtime(true) ) {
			return;
		}
		$this->sendformtime[$n] = microtime(true)+0.7;
		if ( !$this->multiple_floors_mode or !isset($this->floorlist[$n]) ) {
			return;
		}
		switch ( $formId ) {
			case 0;
				$data = [
					'type'=>'form',
					'title'=>TF::DARK_BLUE . '升降機' . ($this->floorlistliftpos[$n][3]===true ? ' (快速模式)' : ''),
					'content'=>TF::YELLOW . "請選擇樓層:\n",
					'buttons'=>[],
				];
				foreach ( $this->floorlist[$n] as $floor ) {
					$data['buttons'][] = ['text'=>$floor[0]];
				}
				break;
		}
		new Form($p, $data, function (Player $p, $data) use ($formId) {
			$this->handleForm($p, $data, $formId);
		});
	}

	public function handleForm ( Player $p, $data, int $formId ) {
		if ( $data === null ) {
			return;
		}
		$n = $p->getName();
		if ( !$this->multiple_floors_mode or !isset($this->floorlist[$n]) ) {
			return;
		}
		switch ( $formId ) {
			case 0;
				$data = (int) $data;
				if ( !isset($this->floorlist[$n][$data]) ) {
					return;
				}
				$pos = $this->floorlistliftpos[$n];
				$world = $pos[0];
				$v3 = $pos[1];
				$fast_mode = $pos[3];
				if ( $data === 0 or $data === (count($this->floorlist[$n])-1) ) {
					$fast_mode = 0;
				}
				$hash = self::lifthash($world, $v3);
				if ( !isset($this->movinglift[$hash]) and $this->islift($world, $v3) ) {
					$this->movinglift[$hash] = [
						0=>Position::fromObject($v3, $world),
						1=>[],
						2=>($this->floorlist[$n][$data][1]>$v3->y ? self::MOVE_UP : self::MOVE_DOWN),
						3=>false,
						4=>false,
						5=>false,
						6=>$this->floorlist[$n][$data][1],
						7=>false,
						8=>false,
						9=>$this->getliftsize($world, $v3),
						10=>$fast_mode,
					];
				} else {
					$p->sendMessage(TF::RED . '!!! 你不在該升降機中或升降機已經移動 !!!');
				}
				unset($this->floorlist[$n]);
				unset($this->floorlistliftpos[$n]);
				break;
		}
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
				$hash = self::lifthash($world, $v3);
				if ( !isset($this->movinglift[$hash]) ) {
					if ( $this->multiple_floors_mode ) {
						$lvh = $world->getMaxY();
						$floorlist = [];
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
										$floorlist[] = [TF::DARK_BLUE . $signText->getLine(1) . TF::RESET . TF::DARK_BLUE . ' (高度:' . ($y-4) . ')' . ($y === $v3->y ? "\n" . TF::DARK_RED . '[*** 目前高度 ***]' : ''), $y];
										if ( !$fast_mode and strtolower($signText->getLine(2)) === 'fast' ) {
											$fast_mode = true;
										}
										goto nextY;
									}
								}
							}
							nextY:
						}
						if ( count($floorlist) !== 0 ) {
							array_unshift($floorlist, [TF::DARK_RED . '最高層 (高度:' . ($lvh-5) . ')', $lvh-1]);
							$floorlist[] = [TF::DARK_RED . '最低層 (高度:1)', 5];
							$this->floorlist[$n] = $floorlist;
							$this->floorlistliftpos[$n] = [$world, $v3, $p, $fast_mode];
							$this->sendform($p, 0);
							return;
						}
					}
					$this->movinglift[$hash] = [
						0=>Position::fromObject($v3, $world),
						1=>[],
						2=>($b_pos->y>$p->getPosition()->y ? self::MOVE_UP : self::MOVE_DOWN),
						3=>false,
						4=>false,
						5=>false,
						6=>false,
						7=>false,
						8=>false,
						9=>$this->getliftsize($world, $v3),
						10=>0,
					];
				} elseif ( $this->movinglift[$hash][3] !== false ) {
					$p->sendMessage(TF::YELLOW . '!!! 升降機稍作停留，請等候數秒鐘 !!!');
				} elseif ( isset($this->movinglift[$hash][1][$p->getId()]) ) {
					$this->movinglift[$hash][2] = self::MOVE_STOP;
					$this->movinglift[$hash][3] = 40;
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
						$btyy = $b->y+3;
						if ( $btyy === $y ) {
							$p->sendMessage(TF::GREEN . '> 升降機已經到達');
							return $cancel;
						}
						$v3 = new Vector3($x, $y, $z);
						$hash = self::lifthash($world, $v3);
						if ( !isset($this->movinglift[$hash]) ) {
							$this->movinglift[$hash] = [
								0=>Position::fromObject($v3, $world),
								1=>[],
								2=>self::MOVE_STOP,
								3=>false,
								4=>false,
								5=>false,
								6=>false,
								7=>true,
								8=>false,
								9=>$this->getliftsize($world, $v3),
								10=>true,
							];
						} else {
							if ( $this->movinglift[$hash][10] === false ) {
								$this->movinglift[$hash][10] = true;
							}
						}
						if ( !isset($this->queue[$hash]) ) {
							$this->queue[$hash] = [];
						}
						$xyzhash = $btyy;

						// xyzhash=>[ 0=>Position,1=>btyy,2=>setblock_timer ]
						$this->queue[$hash][$xyzhash] ??= [
							0=>$b,
							1=>$btyy,
							2=>0,
						];
						return $cancel;
					}
				}
			}
		}
		return $cancel;
	}

	public function liftcheckplayer ( World $world, $hash ) {
		$lift = ($this->movinglift[$hash] ?? null);
		if ( $lift === null ) {
			return;
		}

		$inLiftEntities = [];
		$v3 = $lift[0];
		if ( $lift[9] === 5 ) {
			$addmin = -2;
			$addmax = 2;
		} elseif ( $lift[9] === 3 ) {
			$addmin = -1;
			$addmax = 1;
		} else {
			$addmin = $addmax = 0;
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
				$inLiftEntities[$pl->getId()] = $pl;
			}
		}
		$this->movinglift[$hash][1] = $inLiftEntities;
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

	public static function lifthash ( World $world, Vector3 $v3 ) {
		return ( $world->getFolderName() . ';' . ((int) $v3->x) . ';' . ((int) $v3->z) );
	}

	public function pvp ( EntityDamageEvent $e ) {
		$cause = $e->getCause();
		if ( $cause === EntityDamageEvent::CAUSE_FALL or $cause === EntityDamageEvent::CAUSE_SUFFOCATION ) {
			$entityId = $e->getEntity()->getId();
			foreach ( $this->movinglift as $data ) {
				if ( isset($data[1][$entityId]) ) {
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


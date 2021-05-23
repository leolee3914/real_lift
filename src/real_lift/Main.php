<?php

namespace real_lift;

use pocketmine\level\sound\LaunchSound;
use pocketmine\level\sound\ClickSound;
use pocketmine\level\sound\FizzSound;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\level\Position;
use pocketmine\level\Level;
use pocketmine\entity\Arrow ;
use pocketmine\level\Location;
use pocketmine\nbt\tag\Compound;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\inventory\PlayerInventory;
use pocketmine\Server;
use pocketmine\level\particle\FloatingTextParticle;
use pocketmine\OfflinePlayer;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\utils\Config;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\math\Vector3;
use pocketmine\scheduler\PluginTask;
use pocketmine\block\Block;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\tile\Sign;
use pocketmine\tile\Tile;
use pocketmine\utils\TextFormat as TF;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\level\LevelLoadEvent;
use pocketmine\event\level\LevelUnloadEvent;
use pocketmine\inventory\Inventory;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\block\BlockFactory;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener{
	const MOVE_UP = 0;
	const MOVE_DOWN = 1;
	const MOVE_STOP = 2;
	
	private static $instance;

	public static function getInstance(){
		return static::$instance;
	}

	function onEnable(){
		if(!static::$instance instanceof \real_lift\Main ){
			static::$instance = $this;
		}
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		
		@mkdir($this->getDataFolder());
		$this->config = new Config($this->getDataFolder()."config.yml", Config::YAML, array());
		if ( !isset($this->config->multiple_floors_mode) ) {
			$this->config->multiple_floors_mode = true;
			$this->config->save();
		}
		if ( !isset($this->config->enable3x3) ) {
			$this->config->enable3x3 = true;
			$this->config->save();
		}
		if ( !isset($this->config->enable5x5) ) {
			$this->config->enable5x5 = false;
			$this->config->save();
		}
		if ( !isset($this->config->tp_entity) ) {
			$this->config->tp_entity = true;
			$this->config->save();
		}
		
		$this->movinglift = [];
		
		$this->queue = [];
		
		$this->floorlist = [];
		$this->floorlistliftpos = [];
		$this->sendformtime = [];
		
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(int $currentTick) : void{
			$this->move_lift();
		}), 1);
	}
	
	function pq ( PlayerQuitEvent $e ) {
		$p = $e->getPlayer();
		$n = $p->getName();
		
		unset($this->floorlist[$n]);
		unset($this->floorlistliftpos[$n]);
		unset($this->sendformtime[$n]);
	}
	
	function playsound ( Vector3 $v3, string $sound, ?Player $p = null, float $vol = 1.0, float $pitch = 1.0 ) {
		$pk = new \pocketmine\network\mcpe\protocol\PlaySoundPacket();
		$pk->soundName = $sound;
		$pk->x = $v3->x;
		$pk->y = $v3->y;
		$pk->z = $v3->z;
		$pk->volume = $vol;
		$pk->pitch = $pitch;
		if ( $p !== null ) {
			$p->dataPacket($pk);
			return;
		}
		return $pk;
	}
	
	function move_lift () {
		foreach ( $this->movinglift as $hash=>&$data ) {
			//hash => [ 0=>now Position, 1=>[name=>Player], 2=>(string)status, 3=>waiting, 4=>(int)moved, 5=>(bool)playsound,6=>(int)target-y,7=>(bool)unset,8=>queue,9=>lift_size,10=>fast_mode ]
			$pos = $data[0];
			$lv = $pos->getLevel();
			if ( $lv->isClosed() or !$this->islift($lv, $pos) ) {
				if ( isset($this->queue[$hash]) ) {
					unset($this->queue[$hash]);
				}
				unset($this->movinglift[$hash]);
				continue;
			}
			if ( isset($this->queue[$hash]) and count($this->queue[$hash]) > 0 ) {
				foreach ( $this->queue[$hash] as $xyzhash=>&$dt2 ) {
					if ( --$dt2[2] <= 0 ) {
						$bid = $lv->getBlockIdAt($dt2[0]->x,$dt2[0]->y,$dt2[0]->z);
						switch ( $bid ) {
							case 123;
								$lv->setBlockIdAt($dt2[0]->x,$dt2[0]->y,$dt2[0]->z, 124);
								break;
							case 124;
								$lv->setBlockIdAt($dt2[0]->x,$dt2[0]->y,$dt2[0]->z, 123);
								break;
							case 63;
							case 68;
								##$lv->addParticle(new \pocketmine\level\particle\RedstoneParticle($dt2[0]->add(0.5,0.5,0.5), 2));
								//////////////////////////////
								$f_addParticle = static function ( Player $p, Vector3 $pos, string $pname ) {
									$pk = new \pocketmine\network\mcpe\protocol\SpawnParticleEffectPacket();
									$pk->position = $pos;
									$pk->particleName = $pname;
									$p->dataPacket($pk);
								};
								foreach ( $lv->getPlayers() as $pl ) {
									$f_addParticle($pl, $dt2[0]->add(0.5,0.5,0.5), 'minecraft:redstone_ore_dust_particle');
								}
								//////////////////////////////
								break;
						}
						$dt2[2] = 6;
					}
				}
			}
			$issetqueue = isset($this->queue[$hash]) and count($this->queue[$hash]) > 0;
			if ( $data[7] ) {
				if ( $data[8] !== false ) {
					$data[8] = false;
					if ( isset($this->queue[$hash]) ) {
						$dt = array_shift($this->queue[$hash]);
						if ( is_array($dt) ) {
							$bid = $lv->getBlockIdAt($dt[0]->x,$dt[0]->y,$dt[0]->z);
							if ( $bid === 124 ) {
								$lv->setBlockIdAt($dt[0]->x,$dt[0]->y,$dt[0]->z, 123);
							}
						}
					}
				}
				if ( $issetqueue ) {
					foreach ( $this->queue[$hash] as $xyzhash=>$dt ) {
						if ( $pos->y > $dt[1] ) {
							$data[2] = self::MOVE_DOWN;
							$data[3] = false;
							$data[6] = $dt[1];
							$data[7] = false;
							$data[8] = $dt;
						} elseif ( $pos->y < $dt[1] ) {
							$data[2] = self::MOVE_UP;
							$data[3] = false;
							$data[6] = $dt[1];
							$data[7] = false;
							$data[8] = $dt;
						}
						$data[10] = true;
						break;
					}
				}
				if ( $data[7] ) {
					if ( isset($this->queue[$hash]) ) {
						unset($this->queue[$hash]);
					}
					unset($this->movinglift[$hash]);
				}
				continue;
			}
			if ( $data[3] !== false ) {
				if ( --$data[3] <= 0 ) {
					$data[7] = true;
				}
				if ( $data[3] === 20 and $data[5] ) {
					foreach ( $lv->getPlayers() as $pl ) {
						$this->playsound($pos, 'random.orb', $pl, 1, 2);
					}
				}
				continue;
			}
			$this->liftcheckplayer($lv, $hash);
			$pls = $data[1];
			foreach ( $pls as $entity ) {
				$entity->resetFallDistance();
			}
			$canmove = true;
			if ( $data[2] === self::MOVE_UP ) {
				foreach ( $pls as $k=>$p ) {
					if ( $p instanceof Player ) {
						$p->setMotion(new Vector3(0, 0.8, 0));
						if ( $p->y < ($pos->y-2.4) ) {
							$canmove = false;
						}
					}
				}
			} elseif ( $data[2] === self::MOVE_DOWN ) {
				foreach ( $pls as $k=>$p ) {
					if ( $p instanceof Player ) {
						$p->setMotion(new Vector3(0, -0.4, 0));
						if ( $p->y > ($pos->y-3) ) {
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
					++$data[4];
					continue;
				} else {
					$data[10] = 0;
				}
			}
			$airid = [];
			$stop = false;
			if ( $data[2] === self::MOVE_UP and $canmove ) {
				if ( ($pos->y+1) >= $lv->getWorldHeight() or $pos->y === $data[6] ) {
					$stop = true;
				}
				for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
					for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
						$airid[] = $airid2 = $lv->getBlockIdAt($pos->x+$addx, $pos->y+1, $pos->z+$addz);
						if ( $stop === true or ($airid2 !== 0 and $airid2 !== 20) ) {
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
						$airid[] = $airid2 = $lv->getBlockIdAt($pos->x+$addx, $pos->y-6, $pos->z+$addz);
						if ( $stop === true or ($airid2 !== 0 and $airid2 !== 20) ) {
							$stop = true;
							break 2;
						}
					}
				}
			} else {
				$canmove = false;
			}
			if ( $stop ) {
				if ( $data[4] === 0 ) {
					$data[7] = true;
					continue;
				}
				$data[2] = self::MOVE_STOP;
				$data[3] = 40;
				$data[5] = true;
				continue;
			}
			if ( $canmove ) {
				$liftsize = $this->getliftsize($lv, $pos);
				if ( $liftsize !== $data[9] ) {
					if ( isset($this->queue[$hash]) ) {
						unset($this->queue[$hash]);
					}
					unset($this->movinglift[$hash]);
					continue;
				}
				if ( $data[2] === self::MOVE_UP ) {
					$ii = 0;
					for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
						for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
							$b4142 = ($addx===0&&$addz===0)?41:42;
							$lv->setBlockIdAt($pos->x+$addx, $pos->y, $pos->z+$addz, 0);
							$lv->setBlockIdAt($pos->x+$addx, $pos->y+1, $pos->z+$addz, $b4142);
							$lv->setBlockIdAt($pos->x+$addx, $pos->y-5, $pos->z+$addz, $airid[$ii++]);
							$lv->setBlockIdAt($pos->x+$addx, $pos->y-4, $pos->z+$addz, $b4142);
						}
					}
					$v3y = $pos->y-3;
					foreach ( $pls as $k=>$p ) {
						if ( !$p instanceof Player ) {
							$p->teleport($p->add(0,1));
						}
					}
					$pos->y++;
					++$data[4];
				} elseif ( $data[2] === self::MOVE_DOWN ) {
					$ii = 0;
					for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
						for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
							$b4142 = ($addx===0&&$addz===0)?41:42;
							$lv->setBlockIdAt($pos->x+$addx, $pos->y, $pos->z+$addz, $airid[$ii++]);
							$lv->setBlockIdAt($pos->x+$addx, $pos->y-1, $pos->z+$addz, $b4142);
							$lv->setBlockIdAt($pos->x+$addx, $pos->y-5, $pos->z+$addz, 0);
							$lv->setBlockIdAt($pos->x+$addx, $pos->y-6, $pos->z+$addz, $b4142);
						}
					}
					$v3y = $pos->y-3;
					foreach ( $pls as $k=>$p ) {
						if ( !$p instanceof Player ) {
							$p->teleport($p->add(0,-1));
						}
					}
					$pos->y--;
					++$data[4];
				}
			}
		}
	}
	
	function switchblock ( &$data, $updown=self::MOVE_STOP, $h=0, $pls, $addmin=0, $addmax=0 ) {
		$pos = $data[0];
		$lv = $pos->getLevel();
		if ( $updown === self::MOVE_UP ) {
			if ( $h < 6 or ($pos->y+6) >= $lv->getWorldHeight() ) {
				return false;
			}
		} elseif ( $updown === self::MOVE_DOWN ) {
			if ( $h > -6 or ($pos->y-5) <= 5 ) {
				return false;
			}
			if ( count($pls) !== 0 ) {
				$h = max(-20, $h);
			}
		} else {
			return false;
		}
		$mixy = $pos->y+$h-5;
		$maxy = $pos->y+$h;
		$airid = [];
		for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
			for ( $addy=$mixy;$addy<=$maxy;++$addy ) {
				for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
					$airid[] = $airid2 = $lv->getBlockIdAt($pos->x+$addx, $addy, $pos->z+$addz);
					if ( $airid2 !== 0 and $airid2 !== 20 ) {
						return false;
					}
				}
			}
		}
		foreach ( $pls as $k=>$p ) {
			$p->teleport($p->add(0,$h));
		}
		for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
			for ( $addy=($pos->y-5);$addy<=$pos->y;++$addy ) {
				for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
					$lv->setBlockIdAt($pos->x+$addx, $addy, $pos->z+$addz, array_shift($airid)??0);
				}
			}
		}
		for ( $addx=$addmin;$addx<=$addmax;++$addx ) {
			for ( $addz=$addmin;$addz<=$addmax;++$addz ) {
				$b4142 = ($addx===0&&$addz===0)?41:42;
				$lv->setBlockIdAt($pos->x+$addx, $pos->y+$h, $pos->z+$addz, $b4142);
				$lv->setBlockIdAt($pos->x+$addx, $pos->y+$h-1, $pos->z+$addz, 0);
				$lv->setBlockIdAt($pos->x+$addx, $pos->y+$h-2, $pos->z+$addz, 0);
				$lv->setBlockIdAt($pos->x+$addx, $pos->y+$h-3, $pos->z+$addz, 0);
				$lv->setBlockIdAt($pos->x+$addx, $pos->y+$h-4, $pos->z+$addz, 0);
				$lv->setBlockIdAt($pos->x+$addx, $pos->y+$h-5, $pos->z+$addz, $b4142);
			}
		}
		$pos->y += $h;
		return true;
	}
	
	function sendform ( Player $p, int $formId, $adata = [] ) {
		$n = $p->getName();
		if ( isset($this->sendformtime[$n]) ) {
			if ( $this->sendformtime[$n] > microtime(true) ) {
				return;
			}
		}
		$this->sendformtime[$n] = microtime(true)+0.7;
		if ( !$this->config->multiple_floors_mode or !isset($this->floorlist[$n]) ) {
			return;
		}
		switch ( $formId ) {
			case 0;
				$data = [
				'type'=>'form',
				'title'=>TF::RED.'升降機',
				'content'=>TF::YELLOW."請選擇樓層:\n",
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
	
	function handleForm ( Player $p, $data, int $formId ) {
		$n = $p->getName();
		if ( !$this->config->multiple_floors_mode or !isset($this->floorlist[$n]) ) {
			return;
		}
		if ( $data === null ) {
			return;
		}
		switch ( $formId ) {
			case 0;
				$data = (int)$data;
				if ( !isset($this->floorlist[$n][$data]) ) {
					return;
				}
				$pos = $this->floorlistliftpos[$n];
				$lv = $pos[0];
				$v3 = $pos[1];
				$fast_mode = $pos[3];
				if ( $data === 0 or $data === (count($this->floorlist[$n])-1) ) {
					$fast_mode = 0;
				}
				$hash = $this->lifthash($lv, $v3);
				if ( !isset($this->movinglift[$hash]) and $this->islift($lv, $v3) ) {
					$this->movinglift[$hash] = [
						0=>Position::fromObject($v3, $lv),
						1=>[],
						2=>($this->floorlist[$n][$data][1]>$v3->y ? self::MOVE_UP : self::MOVE_DOWN),
						3=>false,
						4=>0,
						5=>false,
						6=>$this->floorlist[$n][$data][1],
						7=>false,
						8=>false,
						9=>$this->getliftsize($lv, $v3),
						10=>$fast_mode,
					];
				} else {
					$p->sendMessage(TF::RED.'!!! 你不在該升降機中或升降機已經移動 !!!');
				}
				unset($this->floorlist[$n]);
				unset($this->floorlistliftpos[$n]);
				break;
		}
	}
	
	function tap ( PlayerInteractEvent $e ) {
		$p = $e->getPlayer();
		$n = $p->getName();
		$lv = $p->getLevel();
		$b = $e->getBlock();
		$id = $b->getId();
		if ( $id === 41 ) {
			if ( true or (int)floor($p->x) === $b->x and (int)floor($p->z) === $b->z ) {
				if ( $b->y > $p->y ) {
					$v3 = $b;
				} else {
					$v3 = $b->add(0, 5);
				}
				if ( $this->islift($lv, $v3) ) {
					$e->setCancelled(true);
					$hash = $this->lifthash($lv, $v3);
					if ( !isset($this->movinglift[$hash]) ) {
						if ( $this->config->multiple_floors_mode ) {
							$signlist = [];
							$checkxz = [
								[1,1],
								[-1,1],
								[1,-1],
								[-1,-1],
								[2,2],
								[-2,2],
								[2,-2],
								[-2,-2],
								[3,3],
								[-3,3],
								[3,-3],
								[-3,-3],
							];
							$lvh = $lv->getWorldHeight();
							for ( $y=5;$y<$lvh;++$y ) {
								foreach ( $checkxz as $xz ) {
									$x = $v3->x+$xz[0];
									$z = $v3->z+$xz[1];
									$yy = $y-3;
									$bid = $lv->getBlockIdAt($x, $yy, $z);
									if ( $bid === 63 or $bid === 68 ) {
										if ( !isset($signlist[$yy]) ) {
											$signlist[$yy] = [];
										}
										$signlist[$yy][] = new Vector3($x, $yy, $z);
									}
								}
							}
							$floorlist = [];
							$fast_mode = false;
							foreach ( $signlist as $yyy=>$signs ) {
								foreach ( $signs as $sign ) {
									$yy = $sign->y+3;
									$tile = $lv->getTile($sign);
									if ( $tile instanceof Sign ) {
										if ( strtolower($tile->getLine(0)) === '[lift]' ) {
											$floorlist[] = [TF::DARK_BLUE.$tile->getLine(1).' (高度:'.($yy-4).')', $yy];
											if ( strtolower($tile->getLine(2)) === 'fast' ) {
												$fast_mode = true;
											}
											break;
										}
									}
								}
							}
							if ( count($floorlist) > 0 ) {
								$floorlist = array_reverse($floorlist);
								array_unshift($floorlist, [TF::YELLOW.'最高層 (高度:'.($lvh-5).')', $lvh-1]);
								$floorlist[] = [TF::YELLOW.'最低層 (高度:1)', 5];
								$this->floorlist[$n] = $floorlist;
								$this->floorlistliftpos[$n] = [$lv, $v3, $p, $fast_mode];
								$this->sendform($p, 0);
								return;
							}
						}
						$this->movinglift[$hash] = [
							0=>Position::fromObject($v3, $lv),
							1=>[],
							2=>($b->y>$p->y ? self::MOVE_UP : self::MOVE_DOWN),
							3=>false,
							4=>0,
							5=>false,
							6=>false,
							7=>false,
							8=>false,
							9=>$this->getliftsize($lv, $v3),
							10=>0,
						];
					} elseif ( $this->movinglift[$hash][3] !== false ) {
						$p->sendMessage(TF::YELLOW.'!!! 升降機稍作停留，請等候數秒鐘 !!!');
					} elseif ( isset($this->movinglift[$hash][1][$n]) ) {
						$this->movinglift[$hash][2] = self::MOVE_STOP;
						$this->movinglift[$hash][3] = 40;
						$p->sendMessage(TF::GREEN.'> 已停止升降機');
					}
				}
			}
		} elseif ( $id === 123 or $id === 124 ) {
			if ( !$p->isSneaking() ) {
				$checkxz = [
					[1,0],
					[0,1],
					[-1,0],
					[0,-1],
				];
				$cancell = $this->checkqueue($p, $b, $checkxz);
				if ( $cancell ) {
					foreach ( $lv->getPlayers() as $pl ) {
						$this->playsound($b, 'random.click', $pl, 1, 0.6);
					}
					$e->setCancelled(true);
				}
			}
		} elseif ( $id === 63 or $id === 68 ) {
			$tile = $lv->getTile($b);
			if ( $tile instanceof Sign ) {
				if ( strtolower($tile->getLine(0)) === '[lift]' ) {
					$e->setCancelled(true);
					$checkxz = [
						[1,1],
						[-1,1],
						[1,-1],
						[-1,-1],
						[2,2],
						[-2,2],
						[2,-2],
						[-2,-2],
						[3,3],
						[-3,3],
						[3,-3],
						[-3,-3],
					];
					$cancell = $this->checkqueue($p, $b, $checkxz);
					if ( $cancell ) {
						foreach ( $lv->getPlayers() as $pl ) {
							$this->playsound($b, 'random.click', $pl, 1, 0.6);
						}
					}
				}
			}
		}
	}
	
	function checkqueue ( Player $p, Position $b, array $checkxz=[] ) {
		$esetcancell = false;
		$lv = $p->getLevel();
		$lvh = $lv->getWorldHeight();
		if ( $b->y >= 2 and $b->y <= ($lvh-4) ) {
			foreach ( $checkxz as $xz ) {
				$x = $b->x+$xz[0];
				$z = $b->z+$xz[1];
				for ( $y=5;$y<$lvh;++$y ) {
					if ( $this->islift($lv, $x, $y, $z) ) {
						$esetcancell = true;
						$btyy = $b->y+3;
						if ( $btyy === $y ) {
							$p->sendMessage(TF::GREEN.'> 升降機已經到達');
							return $esetcancell;
						}
						$v3 = new Vector3($x, $y, $z);
						$hash = $this->lifthash($lv, $v3);
						if ( !isset($this->movinglift[$hash]) ) {
							$this->movinglift[$hash] = [
								0=>Position::fromObject($v3, $lv),
								1=>[],
								2=>self::MOVE_STOP,
								3=>false,
								4=>0,
								5=>false,
								6=>false,
								7=>true,
								8=>false,
								9=>$this->getliftsize($lv, $v3),
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
						#$xyzhash = $x . ';' . $btyy . ';' . $z;
						$xyzhash = $btyy;
						if ( !isset($this->queue[$hash][$xyzhash]) ) {
							// xyzhash=>[ 0=>Position,1=>btyy,2=>setblock_timer ]
							$this->queue[$hash][$xyzhash] = [
								0=>$b->asPosition(),
								1=>$btyy,
								2=>0,
							];
						}
						return $esetcancell;
					}
				}
			}
		}
		return $esetcancell;
	}
	
	function liftcheckplayer ( Level $lv, $hash ) {
		if ( !isset($this->movinglift[$hash]) ) {
			return;
		}
		$this->movinglift[$hash][1] = [];
		$v3 = $this->movinglift[$hash][0];
		if ( $this->movinglift[$hash][9] === 5 ) {
			$addmin = -2;
			$addmax = 2;
		} elseif ( $this->movinglift[$hash][9] === 3 ) {
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
		if ( $this->config->tp_entity ) {
			$all = $lv->getEntities();
		} else {
			$all = $lv->getPlayers();
		}
		foreach ( $all as $pl ) {
			$ispl = $pl instanceof Player;
			if ( (!$ispl or $pl->getGamemode() !== 3) and $pl->x >= $minx and $pl->x < $maxx and $pl->z >= $minz and $pl->z < $maxz and $pl->y >= $miny and $pl->y < $maxy ) {
				$this->movinglift[$hash][1][$ispl ? $pl->getName() : ('*'.$pl->getId())] = $pl;
			}
		}
		return;
	}
	
	function getliftsize ( Level $lv, Vector3 $pos ) {
		if ( $this->islift2_9($lv, $pos ) ) {
			if ( $this->islift2_25($lv, true, $pos) ) {
				return 5;
			}
			return 3;
		}
		return 1;
	}
	
	function islift2 ( Level $lv, $x, $y=0, $z=0 ) {
		if ( $x instanceof Vector3 ) {
			$x = $x->floor();
			$y = $x->y;
			$z = $x->z;
			
			$x = $x->x;
		}
		return $this->islift($lv, $x, $y, $z, 42);
	}
	
	function islift ( Level $lv, $x, $y=0, $z=0, $bid=41 ) {
		if ( $x instanceof Vector3 ) {
			$x = $x->floor();
			$y = $x->y;
			$z = $x->z;
			
			$x = $x->x;
		}
		$idlist = [$bid, 0, 0, 0, 0, $bid];
		foreach ( $idlist as $i=>$id ) {
			$yy = $y-$i;
			if ( $yy < 0 or $lv->getBlockIdAt($x, $yy, $z) !== $id ) {
				return false;
			}
		}
		return true;
	}
	
	function islift2_25 ( Level $lv, $islift_9, $x, $y=0, $z=0 ) {
		if ( !$this->config->enable5x5 ) {
			return false;
		}
		if ( $x instanceof Vector3 ) {
			$x = $x->floor();
			$y = $x->y;
			$z = $x->z;
			
			$x = $x->x;
		}
		for ( $addx=-2;$addx<=2;++$addx ) {
			for ( $addz=-2;$addz<=2;++$addz ) {
				if ( abs($addx) === 2 or abs($addz) === 2 ) {
					if ( !$this->islift2($lv, $x+$addx, $y, $z+$addz) ) {
						return false;
					}
				}
			}
		}
		return ( $islift_9 or $this->islift2_9($lv, $x, $y, $z) );
	}
	
	function islift2_9 ( Level $lv, $x, $y=0, $z=0 ) {
		if ( !$this->config->enable3x3 ) {
			return false;
		}
		if ( $x instanceof Vector3 ) {
			$x = $x->floor();
			$y = $x->y;
			$z = $x->z;
			
			$x = $x->x;
		}
		for ( $addx=-1;$addx<=1;++$addx ) {
			for ( $addz=-1;$addz<=1;++$addz ) {
				if ( $addx !== 0 or $addz !== 0 ) {
					if ( !$this->islift2($lv, $x+$addx, $y, $z+$addz) ) {
						return false;
					}
				} else {
					if ( !$this->islift($lv, $x, $y, $z) ) {
						return false;
					}
				}
			}
		}
		return true;
	}
	
	function lifthash ( $lv, $x, $z=0 ) {
		if ( $x instanceof Vector3 ) {
			$z = $x->z;
			
			$x = $x->x;
		}
		if ( $lv instanceof Level ) {
			$lv = $lv->getFolderName();
		}
		return ( $lv . ';' . $x . ';' . $z );
	}
	
	function pvp ( EntityDamageEvent $e ) {
		$p = $e->getEntity();
		if ( !$p instanceof Player or $e->isCancelled() ) {
			return;
		}
		$n = $p->getName();
		$cause = $e->getCause();
		if ( $cause === EntityDamageEvent::CAUSE_FALL or $cause === EntityDamageEvent::CAUSE_SUFFOCATION ) {
			foreach ( $this->movinglift as $hash=>$data ) {
				if ( isset($data[1][$n]) ) {
					$e->setCancelled(true);
					return;
				}
			}
		}
	}
	
}

class Form implements \pocketmine\form\Form {
	protected $formData = [];
	protected $closure = null;
	
	function __construct ( ?Player $p, array $formData, ?\Closure $closure = null ) {
		$this->formData = $formData;
		$this->closure = $closure;
		if ( $p !== null ) {
			$p->sendForm($this);
		}
	}
	
	public function handleResponse(Player $player, $data) : void {
		if ( $this->closure !== null ) {
			($this->closure)($player, $data);
		}
	}
	
	function jsonSerialize () {
		return $this->formData;
	}
	
}


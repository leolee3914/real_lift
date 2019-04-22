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

class Main extends PluginBase implements Listener{
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
		if ( !isset($this->config->multiple_floors_mode_formid) ) {
			$this->config->multiple_floors_mode_formid = 5;
			$this->config->save();
		}
		
		$this->movinglift = [];
		
		$this->queue = [];
		
		$this->floorlist = [];
		$this->floorlistliftpos = [];
		
		$this->getScheduler()->scheduleRepeatingTask(new callbackTask([$this, 'move_lift']), 2);
	}
	
	function pq ( PlayerQuitEvent $e ) {
		$p = $e->getPlayer();
		$n = $p->getName();
		if ( isset($this->floorlist[$n]) ) {
			unset($this->floorlist[$n]);
		}
		if ( isset($this->floorlistliftpos[$n]) ) {
			unset($this->floorlistliftpos[$n]);
		}
	}
	
	function playsound ( Vector3 $v3, string $sound, $p = null, $vol = 1, $pitch = 1 ) {
		if ( !is_numeric($vol) ) {
			$vol = 1;
		}
		if ( !is_numeric($pitch) ) {
			$pitch = 1;
		}
		$pk = new \pocketmine\network\mcpe\protocol\PlaySoundPacket();
		$pk->soundName = $sound;
		$pk->x = $v3->x;
		$pk->y = $v3->y;
		$pk->z = $v3->z;
		$pk->volume = $vol;
		$pk->pitch = $pitch;
		if ( $p instanceof Player ) {
			$p->dataPacket($pk);
			return;
		}
		return $pk;
	}
	
	function move_lift () {
		foreach ( $this->movinglift as $hash=>&$data ) {
			//hash => [ 0=>now Position, 1=>[name=>Player], 2=>(string)status, 3=>waiting, 4=>(int)moved, 5=>(bool)playsound,6=>(int)target-y,7=>(bool)unset,8=>queue ]
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
								$lv->addParticle(new \pocketmine\level\particle\RedstoneParticle($dt2[0]->add(0.5,0.5,0.5), 2));
								break;
						}
						$dt2[2] = 3;
					}
				}
			}
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
				if ( isset($this->queue[$hash]) and count($this->queue[$hash]) > 0 ) {
					foreach ( $this->queue[$hash] as $xyzhash=>$dt ) {
						if ( $pos->y > $dt[1] ) {
							$data[2] = 'down';
							$data[3] = false;
							$data[6] = $dt[1];
							$data[7] = false;
							$data[8] = $dt;
						} elseif ( $pos->y < $dt[1] ) {
							$data[2] = 'up';
							$data[3] = false;
							$data[6] = $dt[1];
							$data[7] = false;
							$data[8] = $dt;
						}
						break;
					}
				}
				if ( $data[7] ) {
					if ( isset($this->queue[$hash]) ) {
						unset($this->queue[$hash]);
					}
					unset($this->movinglift[$hash]);
				} else {
					$this->liftcheckplayer($lv, $hash);
				}
				continue;
			}
			if ( $data[3] !== false ) {
				if ( --$data[3] <= 0 ) {
					$data[7] = true;
				}
				if ( $data[3] === 20 and $data[5] ) {
					foreach ( $lv->getPlayers() as $pl ) {
						$this->playsound($pos, 'random.orb', $pl, 1, 3);
					}
				}
				continue;
			}
			$pls = $data[1];
			#$p = $data[1];
			$canmove = true;
			#if ( $p instanceof Player ) {
			if ( count($pls) > 0 ) {
				foreach ( $pls as $k=>$p ) {
					if ( $p->isOnline() and $this->inlift($lv, $p, $pos) ) {
						if ( $data[2] === 'up' ) {
							$p->setMotion(new Vector3(0, 0.32, 0));
							$p->resetFallDistance();
							if ( $p->y < ($pos->y-2.5) ) {
								$canmove = false;
							}
						} elseif ( $data[2] === 'down' ) {
							$p->setMotion(new Vector3(0, -0.18, 0));
							$p->resetFallDistance();
							if ( $p->y > ($pos->y-3) ) {
								$canmove = false;
							}
						}
					} else {
						#$data[1] = null;
						unset($data[1][$k]);
					}
				}
			}
			if ( $data[2] === 'up' ) {
				$airid = $lv->getBlockIdAt($pos->x, $pos->y+1, $pos->z);
				if ( ($pos->y+1) >= $lv->getWorldHeight() or ($airid !== 0 and $airid !== 20) or $pos->y === $data[6] ) {
					if ( $data[4] === 0 ) {
						$data[7] = true;
						continue;
					}
					$data[2] = 'stop';
					$data[3] = 30;
					$data[5] = true;
					continue;
				}
			} elseif ( $data[2] === 'down' ) {
				$airid = $lv->getBlockIdAt($pos->x, $pos->y-6, $pos->z);
				if ( $pos->y <= 5 or ($airid !== 0 and $airid !== 20) or $pos->y === $data[6] ) {
					if ( $data[4] === 0 ) {
						$data[7] = true;
						continue;
					}
					$data[2] = 'stop';
					$data[3] = 30;
					$data[5] = true;
					continue;
				}
			} else {
				$canmove = false;
			}
			if ( $canmove ) {
				if ( $data[2] === 'up' ) {
					$lv->setBlockIdAt($pos->x, $pos->y, $pos->z, 0);
					$lv->setBlockIdAt($pos->x, $pos->y+1, $pos->z, 41);
					$lv->setBlockIdAt($pos->x, $pos->y-5, $pos->z, $airid);
					$lv->setBlockIdAt($pos->x, $pos->y-4, $pos->z, 41);
					$pos->y++;
					++$data[4];
				} elseif ( $data[2] === 'down' ) {
					$lv->setBlockIdAt($pos->x, $pos->y, $pos->z, $airid);
					$lv->setBlockIdAt($pos->x, $pos->y-1, $pos->z, 41);
					$lv->setBlockIdAt($pos->x, $pos->y-5, $pos->z, 0);
					$lv->setBlockIdAt($pos->x, $pos->y-6, $pos->z, 41);
					$pos->y--;
					++$data[4];
				}
			}
		}
	}
	
	function sendform ( Player $p, int $id, $adata = [] ) {
		$n = $p->getName();
		if ( !$this->config->multiple_floors_mode or !isset($this->floorlist[$n]) ) {
			return;
		}
		$pk = new \pocketmine\network\mcpe\protocol\ModalFormRequestPacket();
		$pk->formId = $id;
		switch ( $id ) {
			case $this->config->multiple_floors_mode_formid;
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
		$pk->formData = json_encode($data);
		$p->dataPacket($pk);
	}
	
	function pk ( DataPacketReceiveEvent $e ) {
		$p = $e->getPlayer();
		$n = $p->getName();
		if ( !$this->config->multiple_floors_mode or !isset($this->floorlist[$n]) ) {
			return;
		}
		$pk = $e->getPacket();
		if ( $pk instanceof \pocketmine\network\mcpe\protocol\ModalFormResponsePacket ) {
			$data = json_decode($pk->formData, true);
			if ( $data === null ) {
				return;
			}
			switch ( $pk->formId ) {
				case $this->config->multiple_floors_mode_formid;
					$data = (int)$data;
					if ( !isset($this->floorlist[$n][$data]) ) {
						
						return;
					}
					$pos = $this->floorlistliftpos[$n];
					$lv = $pos[0];
					$v3 = $pos[1];
					$hash = $this->lifthash($lv, $v3);
					if ( !isset($this->movinglift[$hash]) and $this->islift($lv, $v3) and $this->inlift($lv, $p, $v3) ) {
						$this->movinglift[$hash] = [
							0=>Position::fromObject($v3, $lv),
							1=>[],
							2=>($this->floorlist[$n][$data][1]>$v3->y ? 'up' : 'down'),
							3=>false,
							4=>0,
							5=>false,
							6=>$this->floorlist[$n][$data][1],
							7=>false,
							8=>false,
						];
						$this->liftcheckplayer($lv, $hash);
					} else {
						$p->sendMessage(TF::RED.'!!! 你不在該升降機中或升降機已經移動 !!!');
					}
					unset($this->floorlist[$n]);
					unset($this->floorlistliftpos[$n]);
					break;
			}
		}
	}
	
	function tap ( PlayerInteractEvent $e ) {
		$p = $e->getPlayer();
		$n = $p->getName();
		$lv = $p->getLevel();
		$b = $e->getBlock();
		$id = $b->getId();
		if ( $id === 41 ) {
			if ( (int)floor($p->x) === $b->x and (int)floor($p->z) === $b->z ) {
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
							foreach ( $signlist as $yyy=>$signs ) {
								foreach ( $signs as $sign ) {
									$yy = $sign->y+3;
									$tile = $lv->getTile($sign);
									if ( $tile instanceof Sign ) {
										if ( strtolower($tile->getLine(0)) === '[lift]' ) {
											$floorlist[] = [TF::DARK_BLUE.$tile->getLine(1).' (高度:'.($yy-4).')', $yy];
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
								$this->floorlistliftpos[$n] = [$lv, $v3, $p];
								$this->sendform($p, $this->config->multiple_floors_mode_formid);
								return;
							}
						}
						$this->movinglift[$hash] = [
							0=>Position::fromObject($v3, $lv),
							1=>[],
							2=>($b->y>$p->y ? 'up' : 'down'),
							3=>false,
							4=>0,
							5=>false,
							6=>false,
							7=>false,
							8=>false,
						];
						$this->liftcheckplayer($lv, $hash);
					} elseif ( $this->movinglift[$hash][3] !== false ) {
						$p->sendMessage(TF::YELLOW.'!!! 升降機稍作停留，請等候數秒鐘 !!!');
					} elseif ( isset($this->movinglift[$hash][1][$n]) ) {
						$this->movinglift[$hash][2] = 'stop';
						$this->movinglift[$hash][3] = 30;
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
								2=>'stop',
								3=>false,
								4=>0,
								5=>false,
								6=>false,
								7=>true,
								8=>false,
							];
							$this->liftcheckplayer($lv, $hash);
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
		foreach ( $lv->getPlayers() as $pl ) {
			if ( $this->inlift(null, $pl, $v3) ) {
				$this->movinglift[$hash][1][$pl->getName()] = $pl;
			}
		}
	}
	
	function inlift ( ?Level $lv, Player $p, $x, $y=0, $z=0 ) : bool {
		if ( $x instanceof Vector3 ) {
			$x = $x->floor();
			$y = $x->y;
			$z = $x->z;
			
			$x = $x->x;
		}
		if ( $lv !== null and $p->getLevel() !== $lv ) {
			return false;
		}
		if ( (int)floor($p->x) === $x and (int)floor($p->z) === $z and $p->y >= ($y-6.5) and $p->y < ($y) ) {
			return true;
		}
		return false;
	}
	
	function islift ( Level $lv, $x, $y=0, $z=0 ) {
		if ( $x instanceof Vector3 ) {
			$x = $x->floor();
			$y = $x->y;
			$z = $x->z;
			
			$x = $x->x;
		}
		static $idlist = [41, 0, 0, 0, 0, 41];
		foreach ( $idlist as $i=>$id ) {
			$yy = $y-$i;
			if ( $yy < 0 or $lv->getBlockIdAt($x, $yy, $z) !== $id ) {
				return false;
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
		if ( $e->getCause() === EntityDamageEvent::CAUSE_FALL ) {
			foreach ( $this->movinglift as $hash=>$data ) {
				if ( isset($data[1][$n]) ) {
					$e->setCancelled(true);
					return;
				}
			}
		}
	}
	
}

class callbackTask extends \pocketmine\scheduler\Task {
    private $callable;
    private $args;
    
    public function __construct(callable $c, $args = []){
		$this->callable = $c;
		$this->args = $args;
    }

    public function onRun(int $currentTick){
		call_user_func_array($this->callable, $this->args);
    }
}
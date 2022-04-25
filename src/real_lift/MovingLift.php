<?php

declare(strict_types=1);

namespace real_lift;

use pocketmine\entity\Entity;
use pocketmine\player\Player;
use pocketmine\world\Position;
use function get_class;

class MovingLift {

	/** @var array<int, Entity|Player> */
	public array $insideEntities = [];

	/** @var array<int, QueueEntry> */
	public array $queue = [];

	public bool $hasQueue = false;

	public function __construct (
		public Position $position,
		public int $movement,
		public ?int $waiting,
		public bool $moving,
		public bool $playSound,
		public int $targetY,
		public bool $unset,
		private int $liftSize,
		public bool $fastMode,
	) {
		$this->verifySize();
	}

	public function verifySize (): void {
		match ( $this->liftSize ) {
			1, 3, 5 => true,
			default => throw new \InvalidArgumentException("Invalid lift size: {$this->liftSize}"),
		};
	}

	public function getSize () : int {
		return $this->liftSize;
	}

	public function __set ( $name, $value ) {
		throw new \Error("Undefined property: " . get_class($this) . "::\$" . $name);
	}

	public function __get ( $name ) {
		throw new \Error("Undefined property: " . get_class($this) . "::\$" . $name);
	}

}


<?php

declare(strict_types=1);

namespace real_lift;

use pocketmine\world\Position;
use function get_class;

class MovingLift {

	public array $insideEntities = [];

	public int $targetY;

	/** @var array<int, QueueEntry> */
	public array $queue = [];

	public bool $hasQueue = false;

	public function __construct (
		public Position $position,
		public int $movement,
		public ?int $waiting,
		public bool $moving,
		public bool $playSound,
		?int $targetY,
		public bool $unset,
		private int $liftSize,
		public bool $fastMode,
	) {
		if ( $targetY !== null ) {
			$this->targetY = $targetY;
		}
		$this->verifyLiftSize();
	}

	public function verifyLiftSize (): void {
		match ( $this->liftSize ) {
			1, 3, 5 => true,
			default => throw new \InvalidArgumentException("Invalid lift size: {$this->liftSize}"),
		};
	}

	public function getLiftSize () : int {
		return $this->liftSize;
	}

	public function __set ( $name, $value ) {
		throw new \Error("Undefined property: " . get_class($this) . "::\$" . $name);
	}

	public function __get ( $name ) {
		throw new \Error("Undefined property: " . get_class($this) . "::\$" . $name);
	}

}


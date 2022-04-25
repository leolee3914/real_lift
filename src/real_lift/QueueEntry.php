<?php

declare(strict_types=1);

namespace real_lift;

use pocketmine\world\Position;
use function get_class;

class QueueEntry {

	public int $buttonBlinkTimer = 0;

	public function __construct (
		public Position $position,
		private int $targetY,
	) {

	}

	public function getTargetY () : int {
		return $this->targetY;
	}

	public function __set ( $name, $value ) {
		throw new \Error("Undefined property: " . get_class($this) . "::\$" . $name);
	}

	public function __get ( $name ) {
		throw new \Error("Undefined property: " . get_class($this) . "::\$" . $name);
	}

}


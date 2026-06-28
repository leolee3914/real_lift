<?php

declare(strict_types=1);

namespace real_lift;

class Locale {

	const MENU_TITLE_LIFT = "menu-title-lift";
	const MENU_TITLE_LIFT_FAST_MODE = "menu-title-lift-fast-mode";
	const SELECT_FLOOR = "select-floor";
	const LIFT_MOVED = "lift-moved";
	const HEIGHT = "height";
	const CURRENT_HEIGHT = "current-height";
	const HIGHEST = "highest";
	const LOWEST = "lowest";
	const LIFT_WAITING = "lift-waiting";
	const STOPPED_LIFT = "stopped-lift";
	const LIFT_ARRIVED = "lift-arrived";

	private static array $data;

	public static function get ( string $key ) : string {
		return (string) (self::$data[$key] ?? $key);
	}

	public static function setData ( array $data ) : void {
		self::$data = $data;
	}

}

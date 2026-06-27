# Real Lift Plugin

A more realistic elevator/lift plugin for PocketMine-MP.

Java version for PaperMC server: [https://github.com/leolee3914/real_lift_java](https://github.com/leolee3914/real_lift_java)

# Installation

1. Download `.phar` plugin from the [release page](https://github.com/leolee3914/real_lift/releases).
2. Place the plugin file to `plugins` folder and delete the old plugin file.
3. Restart the server.

# How to use

## Building an elevator

![](https://i.imgur.com/cYlBxa9.png)

As shown in the images above, place two gold blocks vertically in the same column, separated by exactly 4 blocks of air, to create a 1x1 elevator. Adding iron blocks around the gold blocks allows you to expand the elevator to 3x3 or 5x5 size. No commands or permissions are required.

Elevators will only move vertically through air or glass blocks.

A redstone lamp can be used to call a 1x1 elevator to the current floor. The redstone lamp must be placed directly adjacent to the elevator, exactly one blocks above the ground. If multiple redstone lamps call the same elevator, the elevator will travel to the requested floors in the order the lamps were activated. Each redstone lamp can only control one elevator. If you have multiple elevators nearby, it is recommended to keep them at least 6 blocks apart from each other and use separate redstone lamps.

Sign can also be used to call an elevator. The sign must be placed in a valid position, exactly one blocks above the ground. Fill out the sign exactly as follows:
- Line 1: `[lift]` (case-insensitive)
- Line 2: `(Name of the floor)`
- Line 3: `fast` (optional, if you type 'fast' here, the elevator will always use Fast Mode)

## Using the elevator

1. Tap the redstone lamp or sign to call the elevator to your current floor.
2. Step onto the elevator platform.
3. Tap the top or bottom gold block of the elevator to move up or down respectively. If floor signs are set up and the multiple floors feature is enabled in the config.yml, a menu will pop up for floor selection.
4. A "ding" sound will play when the elevator arrives at its destination.

If tapping a redstone lamp/sign produces no blinking, particle effects, or chat messages, it means the block is placed in an incorrect position or the elevator setup is invalid.

## Fast mode

In this mode, the elevator moves to its destination via teleportation. This mode is triggered automatically in the following situations:
- If any floor sign connected to the elevator has `fast` written on the 3rd line.
- When the elevator is called via a redstone lamp or sign.

This mode will be disabled if the elevator has no floor signs, or if the player selects the highest or lowest floor option from the floor selection menu.

# Configuration

## config.yml

- `multiple_floors_mode`: (boolean) Enables the floor selection menu form.
- `enable3x3`: (boolean) Enables 3x3 elevators (Requires `multiple_floors_mode`).
- `enable5x5`: (boolean) Enables 5x5 elevators; not recommended due to performance (Requires `multiple_floors_mode`).
- `tp_entity`: (boolean) Moves all entities (e.g., mobs, dropped items) inside the elevator along with it.

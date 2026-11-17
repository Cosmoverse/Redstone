<?php

declare(strict_types=1);

namespace redstone\block;

use pocketmine\block\Transparent;

/**
 * Honey block, mostly here so pistons have a second sticky block to work with.
 * PocketMine doesn't ship it, so the sliding and slowdown stuff isn't implemented.
 */
final class HoneyBlock extends Transparent{
}

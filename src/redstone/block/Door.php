<?php

declare(strict_types=1);

namespace redstone\block;

use redstone\block\power\PowerableDoorTrait;
use redstone\block\power\Powerable;

final class Door extends \pocketmine\block\Door implements Powerable{
	use PowerableDoorTrait;
}
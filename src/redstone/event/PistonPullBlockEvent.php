<?php

declare(strict_types=1);

namespace redstone\event;

use pocketmine\block\Block;
use pocketmine\event\block\BlockEvent;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use redstone\block\Piston;

final class PistonPullBlockEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		readonly public Piston $piston,
		Block $block
	){
		parent::__construct($block);
	}
}
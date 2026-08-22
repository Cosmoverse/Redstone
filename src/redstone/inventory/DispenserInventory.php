<?php

declare(strict_types=1);

namespace redstone\inventory;

use pocketmine\inventory\SimpleInventory;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\world\Position;

final class DispenserInventory extends SimpleInventory implements HackyBlockInventory{

	readonly public int $window_type;
	protected Position $holder;

	public function __construct(Position $holder){
		parent::__construct(9);
		$this->holder = $holder;
		$this->window_type = WindowTypes::DISPENSER;
	}

	public function getHolder() : Position{
		return $this->holder;
	}
}
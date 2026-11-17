<?php

declare(strict_types=1);

namespace redstone\block;

use Closure;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function count;

/**
 * Figures out which blocks a piston drags along when it pushes or pulls, following slime and honey
 * blocks the way Java's piston structure resolver does. Make a new one for every push or pull.
 */
final class PistonStructureResolver{

	private int $piston_hash;
	private ?int $vacated_hash;

	/** @var list<Block> */
	private array $blocks = [];

	/** @var array<int, true> */
	private array $hashes = [];

	/** @var array<int, bool> */
	private array $movable = [];

	/**
	 * @param Closure(Block, Vector3) : bool $can_move
	 * @param Vector3|null $vacated a spot that counts as empty even though something is there, i.e. the arm while pulling
	 */
	public function __construct(
		Vector3 $piston_pos,
		readonly private int $direction,
		readonly private Closure $can_move,
		?Vector3 $vacated = null
	){
		$this->piston_hash = World::blockHash($piston_pos->x, $piston_pos->y, $piston_pos->z);
		$this->vacated_hash = $vacated !== null ? World::blockHash($vacated->x, $vacated->y, $vacated->z) : null;
	}

	/**
	 * Returns every block that moves when $origin gets moved, in no particular order. Empty when
	 * there's nothing at $origin, null when something in the way refuses to move.
	 *
	 * @return list<Block>|null
	 */
	public function resolve(Block $origin) : ?array{
		if($this->isVacant($origin)){
			return [];
		}
		if(!$this->canMove($origin) || !$this->addBlockLine($origin)){
			return null;
		}
		// blocks grows while we walk it, so no foreach here
		for($i = 0; $i < count($this->blocks); ++$i){
			$block = $this->blocks[$i];
			if(self::isSticky($block) && !$this->addBranchingBlocks($block)){
				return null;
			}
		}
		return $this->blocks;
	}

	public static function isSticky(Block $block) : bool{
		return $block->getTypeId() === BlockTypeIds::SLIME || $block instanceof HoneyBlock;
	}

	private static function canStick(Block $a, Block $b) : bool{
		// yeah, slime and honey refuse to stick to each other, flying machines rely on that
		if(($a instanceof HoneyBlock && $b->getTypeId() === BlockTypeIds::SLIME) || ($b instanceof HoneyBlock && $a->getTypeId() === BlockTypeIds::SLIME)){
			return false;
		}
		return self::isSticky($a) || self::isSticky($b);
	}

	private static function hash(Block $block) : int{
		return World::blockHash($block->position->x, $block->position->y, $block->position->z);
	}

	private function isVacant(Block $block) : bool{
		return $block->canBeReplaced() || $block->canBeFlowedInto() || self::hash($block) === $this->vacated_hash;
	}

	private function canMove(Block $block) : bool{
		// cached so BlockTeleportEvent fires once per block, branches tend to revisit the same spots
		return $this->movable[self::hash($block)] ??= !($block instanceof PistonArmBlock) && ($this->can_move)($block, $block->position->getSide($this->direction));
	}

	private function add(Block $block) : bool{
		if(count($this->blocks) >= Piston::PUSH_DISTANCE){
			return false;
		}
		$this->blocks[] = $block;
		$this->hashes[self::hash($block)] = true;
		return true;
	}

	/**
	 * Adds $origin, the sticky chain trailing behind it and everything it shoves in front of it.
	 * An origin that can't move just doesn't get attached, that's not a failure.
	 */
	private function addBlockLine(Block $origin) : bool{
		$hash = self::hash($origin);
		if(isset($this->hashes[$hash]) || $hash === $this->piston_hash || $this->isVacant($origin) || !$this->canMove($origin)){
			return true;
		}

		$line = [$origin];
		$current = $origin;
		$behind = Facing::opposite($this->direction);
		while(self::isSticky($current)){
			$next = $current->getSide($behind);
			$next_hash = self::hash($next);
			if(isset($this->hashes[$next_hash]) || $next_hash === $this->piston_hash || $this->isVacant($next) || !self::canStick($current, $next) || !$this->canMove($next)){
				break;
			}
			$line[] = $next;
			$current = $next;
		}
		if(count($this->blocks) + count($line) > Piston::PUSH_DISTANCE){
			return false;
		}
		foreach($line as $block){
			$this->add($block);
		}

		// stuff in front gets pushed no matter if it's sticky
		$current = $origin;
		while(true){
			$next = $current->getSide($this->direction);
			$next_hash = self::hash($next);
			if(isset($this->hashes[$next_hash]) || $this->isVacant($next)){
				return true;
			}
			if($next_hash === $this->piston_hash || !$this->canMove($next) || !$this->add($next)){
				return false;
			}
			$current = $next;
		}
	}

	private function addBranchingBlocks(Block $block) : bool{
		$axis = Facing::axis($this->direction);
		foreach(Facing::ALL as $face){
			if(Facing::axis($face) === $axis){
				continue;
			}
			$side = $block->getSide($face);
			if(self::canStick($block, $side) && !$this->addBlockLine($side)){
				return false;
			}
		}
		return true;
	}
}

<?php

declare(strict_types=1);

namespace redstone\block;

use pocketmine\block\Block;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockTypeInfo;
use pocketmine\block\Transparent;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\event\block\BlockTeleportEvent;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use redstone\block\power\Movable;
use redstone\block\power\Powerable;
use redstone\block\power\PowerableTrait;
use redstone\block\tile\piston\PistonArm;
use redstone\block\tile\piston\PistonMoveInfo;
use redstone\block\utils\HelperUtils;
use redstone\event\PistonPullBlockEvent;
use redstone\event\PistonPushBlockEvent;
use redstone\vanilla\ExtraVanillaBlocks;
use redstone\world\RedstoneWorld;
use redstone\world\sound\PistonInSound;
use redstone\world\sound\PistonOutSound;
use ReflectionMethod;
use RuntimeException;
use function abs;
use function assert;
use function count;

class Piston extends Transparent implements Powerable, Movable{
	use OptimizedBlockTrait;
	use PowerableTrait;

	public const int PUSH_DISTANCE = 12;

	public const int STATE_CONTRACT_IDLE = 0;
	public const int STATE_CONTRACT_BEGIN = 1;
	public const int STATE_RETRACT_BEGIN = 2;
	public const int STATE_RETRACT_WAITING = 3;
	public const int STATE_RETRACT_IDLE = 4;

	protected int $facing = Facing::NORTH;

	/** @var self::STATE_* */
	protected int $state = self::STATE_RETRACT_IDLE;

	private(set) int $activation_delay = 0;
	private(set) int $deactivation_delay = 0;
	private(set) bool $requires_strong_power = false;

	public function __construct(
		BlockIdentifier $idInfo,
		string $name,
		BlockTypeInfo $typeInfo,
		readonly protected bool $sticky
	){
		parent::__construct($idInfo, $name, $typeInfo);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
	}

	public function readStateFromWorld() : Block{
		$result = parent::readStateFromWorld();
		if($result !== $this){
			return $result;
		}

		$tile = $this->position->getWorld()->getTileAt($this->position->x, $this->position->y, $this->position->z);
		if($tile instanceof PistonArm){
			$this->state = $tile->state;
		}
		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTileAt($this->position->x, $this->position->y, $this->position->z);
		assert($tile instanceof PistonArm);
		$tile->state = $this->state;
		$tile->sticky = $this->sticky;
		$tile->clearSpawnCompoundCache();
		$this->executeState();
	}

	public function isSticky() : bool{
		return $this->sticky;
	}

	public function getFacing() : int{
		return $this->facing;
	}

	public function setFacing(int $facing) : self{
		$this->facing = $facing;
		return $this;
	}

	/**
	 * @return self::STATE_*
	 */
	public function getState() : int{
		return $this->state;
	}

	/**
	 * @param self::STATE_* $state
	 * @return self
	 */
	public function setState(int $state) : self{
		$this->state = $state;
		return $this;
	}

	private function executeState() : void{
		$initial_state = $this->state;
		do{
			$old_state = $this->state;
			switch($this->state){
				case self::STATE_CONTRACT_BEGIN:
					$block = $this->getSide($this->getSideFacing());
					if($block instanceof PistonArmBlock){
						if($block->getFacing() !== $this->facing){
							$this->setState(self::STATE_RETRACT_BEGIN);
						}else{
							$this->setState(self::STATE_CONTRACT_IDLE);
						}
					}elseif($this->pushBlocks()){
						$this->setState(self::STATE_CONTRACT_IDLE);
						$this->position->world->addSound($this->position->add(0.5, 0.5, 0.5), new PistonOutSound());
					}else{
						$this->setState(self::STATE_RETRACT_IDLE);
					}
					break;
				case self::STATE_CONTRACT_IDLE:
					$block = $this->getSide($this->getSideFacing());
					if(!($block instanceof PistonArmBlock) || $block->getFacing() !== $this->facing){
						$block->position->world->setBlockAt($block->position->x, $block->position->y, $block->position->z, ExtraVanillaBlocks::PISTON_ARM_BLOCK()->setFacing($this->facing), false);
					}
					break;
				case self::STATE_RETRACT_BEGIN:
					$block = $this->getSide($this->getSideFacing());
					if($block instanceof PistonArmBlock && $block->getFacing() === $this->facing){
						$this->setState(self::STATE_RETRACT_WAITING);
					}else{
						$this->setState(self::STATE_RETRACT_IDLE);
					}
					break;
				case self::STATE_RETRACT_WAITING:
					$this->position->world->scheduleDelayedBlockUpdate($this->position, RedstoneWorld::redstoneTicks(1));
					break;
				case self::STATE_RETRACT_IDLE:
					$block = $this->getSide($this->getSideFacing());
					if($block instanceof PistonArmBlock && $block->getFacing() === $this->facing){
						$block->position->world->setBlockAt($block->position->x, $block->position->y, $block->position->z, VanillaBlocks::AIR());
					}
					break;
				default:
					throw new RuntimeException("Unexpected state: {$this->state}");
			}
		}while($old_state !== $this->state);
		if($this->state !== $initial_state){
			$this->position->world->setBlockAt($this->position->x, $this->position->y, $this->position->z, $this, false);
		}
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			if(abs($player->getPosition()->x - $this->position->x) < 2 && abs($player->getPosition()->z - $this->position->z) < 2){
				$y = $player->getEyePos()->y;

				if($y - $this->position->y > 2){
					$this->facing = Facing::UP;
				}elseif($this->position->y - $y > 0){
					$this->facing = Facing::DOWN;
				}else{
					$this->facing = $player->getHorizontalFacing();
				}
			}else{
				$this->facing = $player->getHorizontalFacing();
			}
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->state !== self::STATE_RETRACT_IDLE){
			$this->setState(self::STATE_RETRACT_IDLE);
			$this->executeState();
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	private function canBlockBeBroken(Block $block) : bool{
		return $block->isTransparent() && !$block->isSolid();
	}

	private function canBlockBeMoved(Block $block, Vector3 $future_pos) : bool{
		if($this->canBlockBeBroken($block) || $block->canBeFlowedInto() || !$block->getBreakInfo()->isBreakable()){
			return false;
		}
		if($block instanceof Movable ? !$block->canBeMoved() : ($block->position->world->getTileAt($block->position->x, $block->position->y, $block->position->z) !== null)){
			return false;
		}
		($ev = new BlockTeleportEvent($block, $future_pos))->call();
		return !$ev->isCancelled();
	}

	private function pullBlocks() : bool{
		$facing = $this->getSideFacing();
		$block = $this->getSide($facing, 2);
		$future_pos = $this->position->getSide($facing);
		$future_block = $this->position->world->getBlockAt($future_pos->x, $future_pos->y, $future_pos->z);
		if(!($future_block instanceof PistonArmBlock) && !$future_block->canBeReplaced() && !$future_block->canBeFlowedInto()){
			return false;
		}
		$ev = new PistonPullBlockEvent($this, $block);
		if(!$this->canBlockBeMoved($block, $future_pos)){
			$ev->cancel();
		}
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->position->world->setBlockAt($future_pos->x, $future_pos->y, $future_pos->z, VanillaBlocks::AIR(), false);
		HelperUtils::moveBlockAndTile($this->position->world, $block->position, $future_pos);
		return true;
	}

	private function pushBlocks() : bool{
		$movements = [];
		$facing = $this->getSideFacing();
		for($i = 0; $i <= self::PUSH_DISTANCE; $i++){
			$block = $this->getSide($facing, 1 + $i);
			if($block->canBeReplaced() || $block->canBeFlowedInto()){
				break;
			}
			if($i === self::PUSH_DISTANCE){
				return false;
			}
			$future_pos = $block->position->getSide($facing);
			if($block instanceof PistonArmBlock || !$this->canBlockBeMoved($block, $future_pos)){
				return false;
			}
			$tile = $block->position->world->getTile($block->position);
			$movements[] = new PistonMoveInfo($block, $tile, $block->position, $future_pos);
		}
		$arm_pos = $this->position->getSide($facing);
		$ev = new PistonPushBlockEvent($this, $arm_pos, $movements);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->pushEntities(count($movements));
		foreach(HelperUtils::reverse($movements) as $movement){
			HelperUtils::moveBlockAndTile($this->position->world, $movement->from, $movement->to);
		}
		$this->position->world->useBreakOn($arm_pos);
		return true;
	}

	private function pushEntities(int $distance) : void{
		$facing = $this->getSideFacing();
		$facing_offset = Vector3::zero()->getSide($facing, $distance);
		$arm_pos = $this->position->getSide($facing);
		$target_pos = $arm_pos->getSide($facing)->add(0.5, 0.5, 0.5);
		foreach($this->position->world->getNearbyEntities(AxisAlignedBB::one()
			->contract(0.0625, 0.0625, 0.0625)
			->offset($arm_pos->x, $arm_pos->y, $arm_pos->z)
			->offset($facing_offset->x, $facing_offset->y, $facing_offset->z)) as $entity){
			if($entity->isFlaggedForDespawn() || $entity->isClosed()){
				continue;
			}
			$pos = $entity->getPosition();
			$diff = Vector3::zero()->getSide($facing);
			$diff->x *= abs($target_pos->x - $pos->x);
			$diff->y *= abs($target_pos->y - $pos->y);
			$diff->z *= abs($target_pos->z - $pos->z);
			$_move = new ReflectionMethod($entity, "move");
			$_updateMovement = new ReflectionMethod($entity, "updateMovement");
			$_move->invoke($entity, $diff->x, $diff->y, $diff->z);
			$_updateMovement->invoke($entity);
		}
	}

	public function getSideFacing() : int{
		return $this->facing >= 2 ? Facing::opposite($this->facing) : $this->facing;
	}

	public function isPowered() : bool{
		return $this->state !== self::STATE_RETRACT_IDLE;
	}

	public function acceptsPowerFromSide(int $side) : bool{
		return $side !== $this->getSideFacing();
	}

	protected function onReceivePower(int $power) : void{
		if($power > 0){
			if($this->state !== self::STATE_CONTRACT_BEGIN && $this->state !== self::STATE_CONTRACT_IDLE && $this->state !== self::STATE_RETRACT_BEGIN){
				$next_state = self::STATE_CONTRACT_BEGIN;
			}else{
				$next_state = null;
			}
		}else{
			if($this->state !== self::STATE_RETRACT_BEGIN && /*$this->state !== self::STATE_RETRACT_WAITING && */$this->state !== self::STATE_RETRACT_IDLE && $this->state !== self::STATE_CONTRACT_BEGIN){
				$next_state = self::STATE_RETRACT_BEGIN;
			}else{
				$next_state = null;
			}
		}
		if($next_state !== null){
			$this->position->world->setBlockAt($this->position->x, $this->position->y, $this->position->z, $this->setState($next_state), false);
		}
	}

	public function onScheduledUpdate() : void{
		if($this->state === self::STATE_RETRACT_WAITING){
			if($this->sticky){
				$this->pullBlocks();
			}
			$this->position->world->addSound($this->position->add(0.5, 0.5, 0.5), new PistonInSound());
			$this->position->world->setBlockAt($this->position->x, $this->position->y, $this->position->z, $this->setState(self::STATE_RETRACT_IDLE), false);
		}
	}

	public function canBeMoved() : bool{
		return $this->state === self::STATE_RETRACT_IDLE;
	}
}
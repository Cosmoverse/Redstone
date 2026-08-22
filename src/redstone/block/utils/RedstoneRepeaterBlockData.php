<?php

declare(strict_types=1);

namespace redstone\block\utils;

use redstone\world\BlockData;

final class RedstoneRepeaterBlockData implements BlockData{

	public const int OPERATION_SWITCH_ON = 0;
	public const int OPERATION_DISTRIBUTE = 1;
	public const int OPERATION_SWITCH_RECALCULATE = 2;

	/**
	 * @param self::OPERATION_* $operation
	 */
	public function __construct(
		public int $operation
	){}
}
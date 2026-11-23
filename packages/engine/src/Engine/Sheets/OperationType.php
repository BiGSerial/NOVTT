<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

enum OperationType: string
{
    case SetField = 'set_field';
    case UnsetField = 'unset_field';
    case AddItem = 'add_item';
    case SetItemField = 'set_item_field';
    case RemoveItem = 'remove_item';
    case ReorderItems = 'reorder_items';
}

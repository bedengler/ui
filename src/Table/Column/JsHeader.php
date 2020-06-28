<?php

declare(strict_types=1);

namespace atk4\ui\Table\Column;

use atk4\ui\jsCallback;

/**
 * Implement a callback for a column header dropdown menu.
 */
class JsHeader extends jsCallback
{
    /**
     * Function to call when header menu item is select.
     *
     * @param callable $fx
     */
    public function onSelectItem($fx)
    {
        if (is_callable($fx)) {
            if ($this->triggered()) {
                $param = [$_GET['id'],  $_GET['item'] ?? null];
                $this->set(function () use ($fx, $param) {
                    return call_user_func_array($fx, $param);
                });
            }
        }
    }
}
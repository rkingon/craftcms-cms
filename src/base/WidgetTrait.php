<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\base;

/**
 * WidgetTrait implements the common methods and properties for dashboard widget classes.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
trait WidgetTrait
{
    // Properties
    // =========================================================================

    /**
     * @var int|null The user’s chosen cospan for the widget
     */
    public $colspan;
}

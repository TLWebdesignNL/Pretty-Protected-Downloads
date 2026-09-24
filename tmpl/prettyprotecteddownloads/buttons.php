<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * One download button per file. See ../prettyprotecteddownloads.php for the variables.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<div class="prettyprotecteddownloads prettyprotecteddownloads--buttons d-flex flex-wrap gap-2">
    <?php foreach ($downloads as $download) : ?>
        <?php $label = $download->button ?: ($download->title ?: $download->name); ?>
        <form action="<?php echo $e($actionUrl); ?>" method="post" class="d-inline-block">
            <?php echo $download->hidden; ?>
            <button type="submit" class="btn <?php echo $e($download->class . ' ' . $buttonClass); ?>"
                <?php if ($download->size !== null) : ?>
                    title="<?php echo $e(Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_META', strtoupper($download->extension), HTMLHelper::_('number.bytes', $download->size))); ?>"
                <?php endif; ?>>
                <?php if ($download->icon !== '') : ?>
                    <span class="<?php echo $e($download->icon); ?> me-1" aria-hidden="true"></span>
                <?php endif; ?>
                <?php echo $e($label); ?>
            </button>
        </form>
    <?php endforeach; ?>
</div>

<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * A compact list, one line per file, with its type and size. See
 * ../prettyprotecteddownloads.php for the variables.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<ul class="prettyprotecteddownloads prettyprotecteddownloads--list list-group">
    <?php foreach ($downloads as $download) : ?>
        <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
            <div class="me-auto">
                <?php if ($download->icon !== '') : ?>
                    <span class="<?php echo $e($download->icon); ?> me-1" aria-hidden="true"></span>
                <?php endif; ?>
                <span class="fw-semibold"><?php echo $e($download->label); ?></span>
                <?php if ($download->meta !== '') : ?>
                    <span class="small text-body-secondary ms-1">(<?php echo $e($download->meta); ?>)</span>
                <?php endif; ?>
                <?php if ($download->description !== '') : ?>
                    <div class="small"><?php echo nl2br($e($download->description)); ?></div>
                <?php endif; ?>
            </div>
            <form action="<?php echo $e($actionUrl); ?>" method="post">
                <?php echo $download->hidden; ?>
                <button type="submit" class="btn btn-sm <?php echo $e($download->class . ' ' . $buttonClass); ?>">
                    <?php $text = $download->button ?: Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_DOWNLOAD'); ?>
                    <?php echo $e($text); ?>
                    <?php if ($text !== $download->label) : ?>
                        <span class="visually-hidden">, <?php echo $e($download->label); ?></span>
                    <?php endif; ?>
                </button>
            </form>
        </li>
    <?php endforeach; ?>
</ul>

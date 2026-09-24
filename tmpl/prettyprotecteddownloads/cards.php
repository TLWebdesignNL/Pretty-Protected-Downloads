<?php

/**
 * @package     TLWeb.Plugin
 * @subpackage  Fields.Prettyprotecteddownloads
 *
 * @copyright   Copyright (C) 2026 TLWebdesign. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * A Bootstrap card per file, in a responsive grid. See ../prettyprotecteddownloads.php
 * for the variables.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<div class="prettyprotecteddownloads prettyprotecteddownloads--cards row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
    <?php foreach ($downloads as $download) : ?>
        <div class="col">
            <div class="card h-100 <?php echo $e($cardClass); ?>">
                <div class="card-body d-flex flex-column">
                    <h3 class="h5 card-title"><?php echo $e($download->title ?: $download->name); ?></h3>
                    <?php if ($download->size !== null) : ?>
                        <p class="card-subtitle small text-body-secondary mb-2">
                            <?php echo $e(Text::sprintf('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_META', strtoupper($download->extension), HTMLHelper::_('number.bytes', $download->size))); ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($download->description !== '') : ?>
                        <p class="card-text"><?php echo nl2br($e($download->description)); ?></p>
                    <?php endif; ?>
                    <form action="<?php echo $e($actionUrl); ?>" method="post" class="mt-auto align-self-start">
                        <?php echo $download->hidden; ?>
                        <button type="submit" class="btn <?php echo $e($download->class . ' ' . $buttonClass); ?>">
                            <?php if ($download->icon !== '') : ?>
                                <span class="<?php echo $e($download->icon); ?> me-1" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php echo $e($download->button ?: Text::_('PLG_FIELDS_PRETTYPROTECTEDDOWNLOADS_DOWNLOAD')); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

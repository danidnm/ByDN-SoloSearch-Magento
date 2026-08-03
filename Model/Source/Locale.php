<?php

namespace Bydn\SoloSearch\Model\Source;

class Locale implements \Magento\Framework\Option\ArrayInterface
{
    // Kept in sync with the locales SoloSearch's SearchEngine.locale accepts
    // (suite/app/SearchEngine/Filament/Pages/EditDesign.php) - English is the engine's
    // own default when no locale is set there, same as the empty option here.
    const LOCALES = [
        'en' => 'English',
        'es' => 'Español',
        'fr' => 'Français',
    ];

    /**
     * @return array
     */
    public function toOptionArray()
    {
        $options = [['value' => '', 'label' => __('Use engine default')]];

        foreach (self::LOCALES as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }

        return $options;
    }
}

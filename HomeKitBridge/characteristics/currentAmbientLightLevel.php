<?php

declare(strict_types=1);

class HAPCharacteristicCurrentAmbientLightLevel extends HAPCharacteristic
{
    public function __construct()
    {
        parent::__construct(
            0x6B,
            HAPCharacteristicFormat::Float,
            [
                HAPCharacteristicPermission::PairedRead,
                HAPCharacteristicPermission::Notify
            ],
            0.0001,
            100000,
            null,
            HAPCharacteristicUnit::Lux
        );
    }
}

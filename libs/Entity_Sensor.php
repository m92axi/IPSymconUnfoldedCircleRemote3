<?php

declare(strict_types=1);

class Entity_Sensor
{
    // --- States ---
    public const STATE_ON = 'ON'; // The sensor is active and available.

    // --- Attributes ---
    public const ATTR_VALUE = 'value'; // Current sensor reading.
    public const ATTR_UNIT = 'unit'; // Unit of the sensor value.
    public const ATTR_STATE = 'state'; // Optional availability state (e.g. ON).

    // --- Device Classes ---
    public const DEVICE_CLASS_CUSTOM = 'custom'; // Generic sensor with custom unit
    public const DEVICE_CLASS_BATTERY = 'battery'; // Battery charge in %
    public const DEVICE_CLASS_CURRENT = 'current'; // Electrical current in ampere
    public const DEVICE_CLASS_ENERGY = 'energy'; // Energy in kilowatt-hour
    public const DEVICE_CLASS_HUMIDITY = 'humidity'; // Humidity in %
    public const DEVICE_CLASS_POWER = 'power'; // Power in watt or kilowatt
    public const DEVICE_CLASS_TEMPERATURE = 'temperature'; // Temperature with automatic °C, °F conversion, depending on remote settings. Use native_unit option if the temperature is measured in °F.
    public const DEVICE_CLASS_VOLTAGE = 'voltage'; // Voltage in volt
    public const DEVICE_CLASS_BINARY = 'binary'; // Binary sensor. The binary specific device class is stored in the unit attribute.


}

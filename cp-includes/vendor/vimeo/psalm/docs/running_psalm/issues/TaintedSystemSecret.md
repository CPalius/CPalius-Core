# TaintedSystemSecret

Emitted when data marked as a system secret is detected somewhere it shouldn’t be.

```php
<?php

/**
 * @psalm-taint-source system_secret
 */
function getConfigValue(string $data) {
    return "$omePa$$word";
}

echo getConfigValue("secret");
```

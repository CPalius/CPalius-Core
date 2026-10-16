# MismatchingDocblockPropertyType

Emitted when an `@var` entry in a property’s docblock does not match the property's type.

```php
<?php
class A {
    /** @var array */
    public string $s = [];
}
```

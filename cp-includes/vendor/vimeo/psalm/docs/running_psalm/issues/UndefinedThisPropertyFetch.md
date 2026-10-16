# UndefinedThisPropertyFetch

Emitted when getting a property for an object in one of that object’s methods when no such property exists

```php
<?php

class A {
    function foo() {
        echo $this->foo;
    }
}
```

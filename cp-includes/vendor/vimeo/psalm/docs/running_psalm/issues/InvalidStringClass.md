# InvalidStringClass

Emitted when you have `allowStringToStandInForClass="false"` in your config and you’re passing a string instead of calling a class directly

```php
<?php

class Foo {}
$a = "Foo";
new $a();
```

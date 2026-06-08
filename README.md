# RD Mobility <a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>

### Initial setup

- `composer install`
- `php artisan migrate`
- `php artisan db:seed`
- `npm install`

### Running / deploying

#### On localhost

- `npm run dev` will parse sass / html in the background
- `php artisan serve` will run a local server

#### On production

- `npm run install`
- `npm run build`

### Package documentation

- `filament/filament` Dashboard environment
- `jeffgreco13/filament-breezy` Combine the Laravel Breeze authentication package with Filament 
- `spatie/laravel-permission` Role based permissions 
- `spatie/laravel-medialibrary` Manages all uploaded images 

### Testing

- PestPHP is used for testing: https://pestphp.com/docs/installation
- run: `composer test`

### Todo / known issues
- When running tests, it will by default use the same environment, which causes the DB to be emptied


### 


### converting pricetables from PDF to DB
- convert to excel: https://nanonets.com/image-to-excel
- export as CSV
- run code: 

  $rows = explode("\n", $csvData);
  $widths = explode(";", array_shift($rows));
  $insertQueries = [];

    foreach ($rows as $row) {
    $values = explode(";", $row);
    $height = array_shift($values) * 10;  
    foreach ($values as $index => $value) {
    if (!empty($value)) {
    $width = $widths[$index + 1] * 10;
    $price_cost = (float) $value;
    $price = $price_cost * 1.25;
    $insertQueries[] = "($width, $height, $price_cost, $price, 1)";
    }
    }
    }
    $query = "INSERT INTO `price_table_values` (`width`, `height`, `price_cost`, `price`, `price_table_id`) VALUES\n";
    $query .= implode(",\n", $insertQueries) . ";";


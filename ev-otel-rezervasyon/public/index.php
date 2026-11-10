<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Model\Property;

$p = new Property(1, 'Deniz Manzaralı Villa', 'ev');
echo $p->name; // Ekranda "Deniz Manzaralı Villa" görünmeli
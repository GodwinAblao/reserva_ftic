<?php
// Simple test to check if manual block creation works
require_once 'vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

// Create a test request
$request = Request::create(
    '/test',
    'POST',
    [
        '_token' => 'test',
        'facility' => '1',
        'date' => '2026-05-23',
        'start_time' => '09:00',
        'end_time' => '10:00',
        'title' => 'Test Block',
        'type' => 'Manual',
        'notes' => 'Test notes'
    ]
);

echo "Test Request Data:\n";
echo "Facility: " . $request->request->get('facility') . "\n";
echo "Date: " . $request->request->get('date') . "\n";
echo "Start: " . $request->request->get('start_time') . "\n";
echo "End: " . $request->request->get('end_time') . "\n";
echo "Title: " . $request->request->get('title') . "\n";
echo "Type: " . $request->request->get('type') . "\n";
echo "Notes: " . $request->request->get('notes') . "\n";

// Validate date and time
$date = \DateTime::createFromFormat('!Y-m-d', $request->request->get('date'));
$start = \DateTime::createFromFormat('!H:i', $request->request->get('start_time'));
$end = \DateTime::createFromFormat('!H:i', $request->request->get('end_time'));

echo "\nValidation:\n";
echo "Date valid: " . ($date ? 'Yes' : 'No') . "\n";
echo "Start valid: " . ($start ? 'Yes' : 'No') . "\n";
echo "End valid: " . ($end ? 'Yes' : 'No') . "\n";
echo "End > Start: " . ($end > $start ? 'Yes' : 'No') . "\n";

if ($date) {
    echo "Day of week: " . $date->format('w') . " (0=Sunday)\n";
}

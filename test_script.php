<?php
$req = app()->make('Illuminate\Http\Request');
$req->merge(['start_date'=>'2026-05-01', 'end_date'=>'2026-05-09']);
$ctrl = app()->make('App\Http\Controllers\Api\ReportController');
echo json_encode($ctrl->internalConsumption($req)->getData(true));

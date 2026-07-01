<?php

namespace App\Console\Commands;

use App\Models\TboAirApiLog;
use Illuminate\Console\Command;

class TboAirLogsCommand extends Command
{
    protected $signature = 'tboair:logs
        {id? : Show the full request/response for a single log}
        {--limit=20 : How many recent logs to list}
        {--type= : Filter by type (authenticate|search)}
        {--failed : Only show failed calls}';

    protected $description = 'Inspect logged TBO Air requests and responses.';

    public function handle(): int
    {
        if ($id = $this->argument('id')) {
            return $this->showDetail((int) $id);
        }

        $logs = TboAirApiLog::query()
            ->when($this->option('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($this->option('failed'), fn ($q) => $q->where('successful', false))
            ->latest()
            ->limit((int) $this->option('limit'))
            ->get();

        if ($logs->isEmpty()) {
            $this->warn('No API logs found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'When', 'Type', 'Status', 'ms', 'OK', 'Summary'],
            $logs->map(fn (TboAirApiLog $log): array => [
                $log->id,
                $log->created_at->format('m-d H:i:s'),
                $log->type,
                $log->status_code ?? 'ERR',
                $log->duration_ms,
                $log->successful ? 'yes' : 'NO',
                $log->summary(),
            ])->all(),
        );

        $this->line('Run <info>tboair:logs {id}</info> to see a full request/response.');

        return self::SUCCESS;
    }

    private function showDetail(int $id): int
    {
        $log = TboAirApiLog::find($id);

        if (! $log) {
            $this->error("Log #{$id} not found.");

            return self::FAILURE;
        }

        $this->info("#{$log->id}  [{$log->type}]  {$log->status_code}  {$log->duration_ms}ms  {$log->created_at}");
        $this->line($log->endpoint);

        if ($log->error) {
            $this->error('Error: '.$log->error);
        }

        $this->newLine();
        $this->comment('REQUEST');
        $this->line(json_encode($log->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->comment('RESPONSE');
        $this->line(json_encode($log->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}

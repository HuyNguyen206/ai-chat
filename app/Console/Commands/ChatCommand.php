<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class ChatCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat
                            {message? : Send a single message and print the reply; omit it for an interactive chat}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Chat with Google Gemini (free tier)';

    /**
     * The conversation so far, in Gemini's "contents" format.
     *
     * @var array<int, array{role: string, parts: array<int, array{text: string}>}>
     */
    private array $history = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $message = text('Message', required: true);

        $this->history[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $response = spin(fn() => Http::withHeaders(['x-goog-api-key' => config('services.gemini.api_key')])
                ->timeout(60)
                ->post(sprintf(
                    'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
                    config('services.gemini.model'),
                ), ['contents' => $this->history])
                ->throw()
                ->json(),
            'Running...'
            );


        $this->info($response['candidates'][0]['content']['parts'][0]['text'] ?? 'No response from Gemini.');

        return self::SUCCESS;
    }
}

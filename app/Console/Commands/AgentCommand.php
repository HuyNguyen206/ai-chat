<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class AgentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent
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
        while (true) {
            $message = text('Message', required: true);
            $this->history[] = ['role' => 'user', 'parts' => [['text' => $message]]];

            $response = $this->callAI();

            dump($response);

            $innerLoopApiCallCount = 0;
            $callToolCount = 0;

            while (($functionCalls = $this->getFunctionCall($response)) && $functionCalls->isNotEmpty()) {
                $innerLoopApiCallCount++;

                $parts = $response['candidates'][0]['content']['parts'];
                foreach ($parts as $index => $part) {
                    if (isset($part['functionCall']['args'])) {
                        $part['functionCall']['args'] = (object) ($function['args'] ?? []);
                    }
                    $parts[$index] = $part;
                }
                $this->history[] = ['role' => 'model', 'parts' => $parts];

                $functionCalls->each(function($functionCall) use (&$callToolCount) {
                    $callToolCount++;
                    $function = $functionCall['functionCall'];

                    $result = $this->{$function['name']}(...($function['args'] ?? []));

                    $functionResponse = [
                        'name' => $function['name'],
                        'response' => ['result' => $result],
                    ];
                    if (isset($function['id'])) {
                        $functionResponse['id'] = $function['id'];
                    }
                    $this->history[] = ['role' => 'user', 'parts' => [['functionResponse' => $functionResponse]]];
                    $this->info('This tool has been called: ' . $function['name']);
                });

                $response = $this->callAI();

                dump($response);
            }

                $aiResponseMessage = $response['candidates'][0]['content']['parts'][0]['text'] ?? 'No response from Gemini.';
                $this->history[] = ['role' => 'model', 'parts' => [['text' => $aiResponseMessage]]];
                $this->info($aiResponseMessage);
                $this->info('Inner loop Api Call Count: ' . $innerLoopApiCallCount);
                $this->info('Total call Tool Count: ' . $callToolCount);

        }
    }

    protected function tools(): array
    {
        return [[
            'functionDeclarations' => [
                [
                    'name'        => 'get_current_time',
                    'description' => 'Get the current time of the app.',
//                    'parameters'  => [
//                        'type'       => 'object',
//                        'properties' => [
//                            'order_id' => ['type' => 'string', 'description' => 'The order ID, e.g. ORD-1234'],
//                        ],
//                        'required' => ['order_id'],
//                    ],
                ],
                [
                    'name'        => 'add_log',
                    'description' => 'Add a message to log.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'logMessage' => ['type' => 'string', 'description' => 'The log message to add'],
                        ],
                        'required' => ['logMessage'],
                    ],
                ],
                [
                    'name'        => 'read_file_content',
                    'description' => 'Read the content of a file.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'filePath' => ['type' => 'string', 'description' => 'The path to the file to read'],
                        ],
                        'required' => ['filePath'],
                    ],
                ],
                // more functions...
            ],
        ]];
    }

    public function get_current_time()
    {
        return now()->toDateTimeString() . '|Vietnam time';
    }

    public function add_log(string $logMessage)
    {
        Log::info('Agent log: ' . $logMessage);
    }

    public function read_file_content(string $filePath)
    {
        return file_get_contents(base_path($filePath));
    }

    /**
     * @return mixed
     */
    public function callAI(): mixed
    {
        $response = spin(fn() => Http::withHeaders(['x-goog-api-key' => config('services.gemini.api_key')])
            ->timeout(60)
            ->post(sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
                config('services.gemini.model'),
            ), [
                    'contents' => $this->history,
                    'tools' => $this->tools(),
                ]
            )
            ->throw()
            ->json(),
            'Running...'
        );
        return $response;
    }

    public function getFunctionCall($response)
    {
        $parts = $response['candidates'][0]['content']['parts'];

        return collect($parts)
            ->filter(function($functionCall) {
                return !empty($functionCall['functionCall']);
            });
    }
}

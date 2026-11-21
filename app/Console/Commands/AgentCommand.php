<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
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

            if (!isset($result)) {
                $message = text('Message', required: true);
                $this->history[] = ['role' => 'user', 'parts' => [['text' => $message]]];
            } else {
                $result = null;
            }

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

            dump($response);

            if (!empty($response['candidates'][0]['content']['parts'][0]['functionCall'])) {
                $part = $response['candidates'][0]['content']['parts'][0];
                $function = $part['functionCall'];

                $result = $this->{$function['name']}(...($function['args'] ?? []));

                // Send the model's function-call part back exactly as received
                // (keeps args, id and thoughtSignature intact). `args` must stay a JSON
                // object: an empty PHP array would be encoded as `[]` instead of `{}`.
                $part['functionCall']['args'] = (object) ($function['args'] ?? []);
                $this->history[] = ['role' => 'model', 'parts' => [$part]];

                // functionResponse.response must be a JSON object (Struct), not a scalar.
                $functionResponse = [
                    'name' => $function['name'],
                    'response' => ['result' => $result],
                ];
                if (isset($function['id'])) {
                    $functionResponse['id'] = $function['id'];
                }
                $this->history[] = ['role' => 'user', 'parts' => [['functionResponse' => $functionResponse]]];

            } else {
                $aiResponseMessage = $response['candidates'][0]['content']['parts'][0]['text'] ?? 'No response from Gemini.';
                $this->history[] = ['role' => 'model', 'parts' => [['text' => $aiResponseMessage]]];
                $this->info($aiResponseMessage);

                $result = null;
            }

        }


        return self::SUCCESS;
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
                    'description' => 'Add a log entry.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'logMessage' => ['type' => 'string', 'description' => 'The log message to add'],
                        ],
                        'required' => ['logMessage'],
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
}

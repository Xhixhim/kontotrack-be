<?php

namespace App\Http\Middleware;

use App\Support\Localizer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalizeApiResponse
{
    /**
     * Answer in the language the web app asks for (Accept-Language: sq or en; English when not given).
     *
     * Validation rules use that language directly. Messages the application writes in English, such as
     * business-rule errors and the automatic checks, are translated on the way out, so the people who
     * write those rules keep writing one clear English sentence.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(str_starts_with(strtolower((string) $request->header('Accept-Language')), 'sq') ? 'sq' : 'en');
        $response = $next($request);

        if (app()->getLocale() === 'en' || ! $response instanceof JsonResponse) {
            return $response;
        }

        $body = $response->getData(true);

        if (! is_array($body)) {
            return $response;
        }

        if (is_array($body['errors'] ?? null)) {
            $body['errors'] = array_map(fn (mixed $messages): mixed => is_array($messages) ? array_map($this->text(...), $messages) : $messages, $body['errors']);
            $all = array_merge(...array_values(array_map(fn (mixed $messages): array => is_array($messages) ? array_values($messages) : [], $body['errors'])));

            // Laravel's summary line is built from the first error before it was translated; rebuild it.
            if ($all !== [] && is_string($all[0])) {
                $more = count($all) - 1;
                $body['message'] = $all[0].($more > 0 ? ' '.trans_choice('(and :count more error)|(and :count more errors)', $more, ['count' => $more]) : '');
            }
        } elseif (is_string($body['message'] ?? null)) {
            $body['message'] = Localizer::translate($body['message']);
        }

        $data = $body['data'] ?? null;

        if (is_array($data)) {
            // Automatic checks (dashboard and month-end review), year-end blockers, import results and reminders.
            foreach (['checks' => ['label', 'detail'], 'skipped' => ['reason']] as $list => $fields) {
                foreach (is_array($data[$list] ?? null) ? $data[$list] : [] as $index => $row) {
                    foreach ($fields as $field) {
                        if (is_array($row) && is_string($row[$field] ?? null)) {
                            $body['data'][$list][$index][$field] = Localizer::translate($row[$field]);
                        }
                    }
                }
            }

            if (is_array($data['blockers'] ?? null)) {
                $body['data']['blockers'] = array_map($this->text(...), $data['blockers']);
            }

            foreach (array_is_list($data) ? $data : [] as $index => $row) {
                if (is_array($row) && isset($row['severity'], $row['type']) && is_string($row['title'] ?? null)) {
                    $body['data'][$index]['title'] = Localizer::translate($row['title']);
                }
            }
        }

        return $response->setData($body);
    }

    private function text(mixed $value): mixed
    {
        return is_string($value) ? Localizer::translate($value) : $value;
    }
}

<?php

namespace App\Jobs;

use App\DTO\JobResponseDTO;
use App\Enums\ApiName;
use App\Events\ExceptionHappenEvent;
use App\Events\NotifyUserAboutNewJobsEvent;
use App\Exceptions\ApiKeyNotFoundException;
use App\Jobs\Store\StoreJobs;
use App\Models\ApiKey;
use App\Models\JobCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetJobData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [30, 45, 60];

    public function __construct(
        private readonly int $categoryId,
        private readonly int $totalPages,
        private readonly bool $isLastCategory
    ) {}

    public function handle(): void
    {
        try {
            $apiKey = ApiKey::query()
                ->where('api_name', ApiName::JOB)
                ->where('request_remaining', '>', 0)
                ->orderBy('sent_request')
                ->first();

            ApiKeyNotFoundException::validateApiKey($apiKey);

            $category = JobCategory::with('countries')->findOrFail($this->categoryId);

            if ($category->countries->isEmpty()) {
                throw new \Exception('No countries configured for this job category');
            }

            $this->fetchAndStoreJobs($apiKey, $category);

        } catch (\Exception $e) {
            ExceptionHappenEvent::dispatch($e);
            Log::error('Error on job fetching', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            $this->release(60);
        }
    }

    protected function fetchAndStoreJobs($apiKey, JobCategory $category): void
    {
        foreach ($category->countries as $country) {
            for ($page = 1; $page <= $this->totalPages; $page++) {
                try {
                    logger('Processing', [
                        'category_id' => $category->id,
                        'category_name' => $category->name,
                        'page' => $page,
                        'country' => $country->code
                    ]);

                    $response = Http::job()->retry([100, 200])->get('/search', [
                        'query' => $category->query_name,
                        'page' => $page,
                        'num_pages' => $category->num_page,
                        'date_posted' => $category->timeframe,
                        'country' => $country->code
                    ]);

                    DB::table('api_keys')
                        ->where('id', $apiKey->id)
                        ->update(['request_remaining' => $response->header('X-RateLimit-Requests-Remaining')]);

                    if ($response->status() === 429) {
                        static::dispatch($this->categoryId, $this->totalPages, $this->isLastCategory)
                            ->delay(now()->addMinutes(1));
                        return;
                    }

                    if ($response->ok()) {
                        $jobResponseDTO = JobResponseDTO::fromResponse(
                            $response->json(),
                            $category->id,
                            $category->category_image
                        );
                        StoreJobs::dispatch($jobResponseDTO);
                    }

                    if ($this->isLastCategory && $page === $this->totalPages && $country->is($category->countries->last())) {
                        NotifyUserAboutNewJobsEvent::dispatch();
                        return;
                    }
                } catch (\Exception $e) {
                    ExceptionHappenEvent::dispatch($e);
                    static::dispatch($this->categoryId, $this->totalPages, $this->isLastCategory)
                        ->delay(now()->addMinutes(1));
                    throw $e;
                }
            }
        }
    }
}

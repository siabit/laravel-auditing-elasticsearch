<?php

/**
 * This file is part of the Laravel Auditing package.
 *
 * @author     Arpan Rank <arpan@iconscout.com>
 * @copyright  2018
 *
 * For the full copyright and license information,
 * please view the LICENSE.md file that was distributed
 * with this source code.
 */

namespace Iconscout\Auditing\Drivers;

use Carbon\Carbon;
use Elasticsearch\ClientBuilder;
use Iconscout\Auditing\Jobs\AuditDeleteQueuedModels;
use Iconscout\Auditing\Jobs\AuditIndexQueuedModels;
use Illuminate\Support\Facades\Config;
use OwenIt\Auditing\Contracts\Audit;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\AuditDriver;
use OwenIt\Auditing\Models\Audit as AuditModel;
use Ramsey\Uuid\Uuid;

class ElasticSearch implements AuditDriver
{
    /**
     * @var string
     */
    protected $client = null;

    /**
     * @var string
     */
    protected $index = null;

    /**
     * ElasticSearch constructor.
     */
    public function __construct()
    {
        $this->client = ClientBuilder::create()->setHosts(Config::get('audit.drivers.es.client.hosts', ['localhost:9200']))->build();
        $this->index = Config::get('audit.drivers.es.index', 'laravel_auditing');
    }

    /**
     * Perform an audit.
     *
     * @param \OwenIt\Auditing\Contracts\Auditable $model
     *
     * @return \OwenIt\Auditing\Contracts\Audit
     */
    public function audit(Auditable $model): Audit
    {
        $implementation = Config::get('audit.implementation', AuditModel::class);

        $this->storeAudit($model->toAudit());

        return new $implementation;
    }

    /**
     * Remove older audits that go over the threshold.
     *
     * @param \OwenIt\Auditing\Contracts\Auditable $model
     *
     * @return bool
     */
    public function prune(Auditable $model): bool
    {
        if ($model->getAuditThreshold() > 0) {
            return $this->destroyAudit($model);
        }

        return false;
    }

    public function storeAudit($model)
    {
        $model['created_at'] = Carbon::now()->toDateTimeString();

        if (Config::get('audit.queue', false)) {
            return $this->indexQueueAuditDocument($model);
        }

        return $this->indexAuditDocument($model);
    }

    public function indexQueueAuditDocument($model)
    {
        dispatch((new AuditIndexQueuedModels($model))
                ->onQueue($this->syncWithSearchUsingQueue())
                ->onConnection($this->syncWithSearchUsing()));

        return true;
    }

    public function destroyAudit($model)
    {
        if (Config::get('audit.queue', false)) {
            return $this->deleteQueueAuditDocument($model);
        }

        return $this->deleteAuditDocument($model);
    }

    public function deleteQueueAuditDocument($model)
    {
        dispatch((new AuditDeleteQueuedModels($model))
                ->onQueue($this->syncWithSearchUsingQueue())
                ->onConnection($this->syncWithSearchUsing()));

        return true;
    }

    /**
     * Get the queue that should be used with syncing
     *
     * @return  string
     */
    public function syncWithSearchUsingQueue()
    {
        return config('audit.queue.queue');
    }

    /**
     * Get the queue connection that should be used when syncing.
     *
     * @return string
     */
    public function syncWithSearchUsing()
    {
        return config('audit.queue.connection') ?: config('queue.default');
    }

    public function indexAuditDocument($model)
    {
        $params = [
            'index' => $this->index,
            'id' => (string) Uuid::uuid4(),
            'body' => $model
        ];

        try {
            return $this->client->index($params);
        } catch (\Exception $e) {}
    }

    public function searchAuditDocument($model)
    {
        $skip = $model->getAuditThreshold() - 1;

        $params = [
            'index' => $this->index,
            'size' => 10000 - $skip,
            'from' => $skip,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'term' => [
                                    'auditable_id' => $model->id
                                ]
                            ],
                            [
                                'term' => [
                                    'auditable_type' => $model->getMorphClass()
                                ]
                            ]
                        ]
                    ]
                ],
                'sort' => [
                    'created_at' => [
                        'order' => 'desc'
                    ]
                ]
            ]
        ];

        return $this->client->search($params);
    }

    public function deleteAuditDocument($model)
    {
        $audits = $this->searchAuditDocument($model);
        $audits = $audits['hits']['hits'];

        if (count($audits)) {
            $audit_ids = array_column($audits, '_id');

            foreach ($audit_ids as $audit_id) {
                $params['body'][] = [
                    'delete' => [
                        '_index' => $this->index,
                        '_id' => $audit_id
                    ]
                ];

            }

            return (bool) $this->client->bulk($params);
        }

        return false;
    }

    public function createIndex()
    {
        $params = [
            'index' => $this->index,
            'body' => [
                'settings' => [
                    'number_of_shards' => 3,
                    'number_of_replicas' => 0
                ]
            ]
        ];

        return $this->client->indices()->create($params);
    }

    public function updateAliases()
    {
        $params['body'] = [
            'actions' => [
                [
                    'add' => [
                        'index' => $this->index,
                        'alias' => $this->index.'_write'
                    ]
                ]
            ]
        ];

        return $this->client->indices()->updateAliases($params);
    }

    public function deleteIndex()
    {
        $deleteParams = [
            'index' => $this->index
        ];

        return $this->client->indices()->delete($deleteParams);
    }

    public function existsIndex()
    {
        $params = [
            'index' => $this->index
        ];

        return $this->client->indices()->exists($params);
    }

    public function putMapping()
    {
        $params = [
            'index' => $this->index,
            'body' => [
                '_source' => [
                    'enabled' => true
                ],
                'properties' => [
                    'event' => [
                        'type' => 'keyword',
                        'index' => true,
                    ],
                    'auditable_type' => [
                        'type' => 'keyword',
                        'index' => true,
                    ],
                    'ip_address' => [
                        'type' => 'keyword',
                        'index' => true,
                    ],
                    'url' => [
                        'type' => 'keyword',
                        'index' => true,
                    ],
                    'user_agent' => [
                        'type' => 'keyword',
                        'index' => true,
                    ],
                    'created_at' => [
                        'type' => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss',
                    ],
                    'new_values' => [
                        'properties' => [
                            'created_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss',
                            ],
                            'updated_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss',
                            ],
                            'deleted_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss',
                            ],
                        ]
                    ],
                    'old_values' => [
                        'properties' => [
                            'created_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss',
                            ],
                            'updated_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss',
                            ],
                            'deleted_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $this->client->indices()->putMapping($params);
    }
}

<?php
require_once CHS_PLUGIN_DIR . 'includes/services/class-chs-event-sync-service.php';

class CHS_WebhookHandlerService {

    private CHS_EventSyncService $eventSyncService;

    public function __construct() {
        add_action('init', [$this, 'handleWebhookRequest']);
        $this->eventSyncService = new CHS_EventSyncService();
    }

    public function handleWebhookRequest() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_contains($_SERVER['REQUEST_URI'], 'chs-webhook')) {
            try{
                $json = file_get_contents('php://input');
                $changeEvents = json_decode($json);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($changeEvents)) {
                    throw new Exception('Invalid JSON payload (' . json_last_error_msg() . '): ' . $json);
                }
                foreach($changeEvents as $changeEvent) {
                    // Handle the event
                        switch($changeEvent->model){
                            case 'Event':
                                $this->eventSyncService->syncEvent($changeEvent->id, $changeEvent->changes, $changeEvent->type);
                                break;
                            case 'EventPackage':
                                $this->eventSyncService->syncEventPackage($changeEvent->id, $changeEvent->changes, $changeEvent->type);
                                break;
                            default:
                                // We don't handle this event type
                                break;
                        }
                    error_log(print_r($changeEvent, true));
                }


            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
            // Respond with an empty 200
            exit;
        }
    }
}
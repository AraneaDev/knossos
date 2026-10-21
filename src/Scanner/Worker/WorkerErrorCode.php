<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

/**
 * Every diagnostic code a worker failure is reported under.
 *
 * {@see WorkerException::$diagnosticCode} carries the value; code that reacts
 * to one failure kind (retry, split a batch, name a reason) matches on these
 * cases rather than on a string spelled out again. Each code is documented in
 * docs/operate/recovery-matrix.md, which a test keeps in step with this list.
 */
enum WorkerErrorCode: string
{
    /** The scan or enrichment was cancelled while a request was in flight. */
    case Cancelled = 'WORKER_CANCELLED';
    /** The worker's manifest does not declare a capability the request needs. */
    case CapabilityMismatch = 'WORKER_CAPABILITY_MISMATCH';
    /** A contribution broke the protocol: a fact, read or path the core refuses. */
    case ContributionInvalid = 'WORKER_CONTRIBUTION_INVALID';
    /** A rescan failed for a language whose facts the active graph holds, so the scan aborts. */
    case DegradedIncremental = 'WORKER_DEGRADED_INCREMENTAL';
    /** Every file of a language was left out as too large for the limits in force. */
    case EveryFileLeftOut = 'WORKER_EVERY_FILE_LEFT_OUT';
    /** The worker process exited before answering. */
    case Exited = 'WORKER_EXITED';
    /** A language failed with an error that carries no worker code of its own. */
    case Failed = 'WORKER_FAILED';
    /** The worker wrote an empty frame or one that is not a JSON-RPC object. */
    case FrameInvalid = 'WORKER_FRAME_INVALID';
    /** One response frame outgrew the frame limit. */
    case FrameTooLarge = 'WORKER_FRAME_TOO_LARGE';
    /** Reading from or polling the worker's pipes failed. */
    case IoFailed = 'WORKER_IO_FAILED';
    /** The worker wrote a line that is not JSON. */
    case JsonInvalid = 'WORKER_JSON_INVALID';
    /** The worker's initialize response is not a valid manifest. */
    case ManifestInvalid = 'WORKER_MANIFEST_INVALID';
    /** The worker's output for one request outgrew the output limit. */
    case OutputLimit = 'WORKER_OUTPUT_LIMIT';
    /** The worker declares an output schema the core does not read. */
    case OutputSchemaMismatch = 'WORKER_OUTPUT_SCHEMA_MISMATCH';
    /** Writing a request to the worker failed. */
    case PipeBroken = 'WORKER_PIPE_BROKEN';
    /** The worker speaks a protocol version the core does not. */
    case ProtocolVersionMismatch = 'WORKER_PROTOCOL_VERSION_MISMATCH';
    /** A language needed more requests than the request cap allows. */
    case RequestCap = 'WORKER_REQUEST_CAP';
    /** A request could not be encoded as JSON. */
    case RequestInvalid = 'WORKER_REQUEST_INVALID';
    /** A request frame outgrew the frame limit. */
    case RequestTooLarge = 'WORKER_REQUEST_TOO_LARGE';
    /** A response is valid JSON-RPC but breaks the shape the method returns. */
    case ResponseInvalid = 'WORKER_RESPONSE_INVALID';
    /** The worker answered a request with a JSON-RPC error. */
    case RpcError = 'WORKER_RPC_ERROR';
    /** The worker process could not be started. */
    case StartFailed = 'WORKER_START_FAILED';
    /** A request ran past its deadline. */
    case Timeout = 'WORKER_TIMEOUT';
    /** A response names a request id other than the one in flight. */
    case UnexpectedResponse = 'WORKER_UNEXPECTED_RESPONSE';

    /** The code $error was raised with, or null for one outside this list. */
    public static function of(WorkerException $error): ?self
    {
        return self::tryFrom($error->diagnosticCode);
    }
}

import {
  TestkitAssertionError,
  TestkitNetworkError,
  UnexpectedMockRequestError,
} from "./errors.js";

export {
  TestkitAssertionError,
  TestkitNetworkError,
  UnexpectedMockRequestError,
} from "./errors.js";

export interface MockRequestRecord {
  readonly method: string;
  readonly url: string;
  readonly headers: Readonly<Record<string, string>>;
  readonly body?: string;
  readonly credentials?: RequestCredentials;
}

export interface ExpectedMockRequest {
  readonly method: string;
  readonly url: string;
}

interface QueuedJsonResponse {
  readonly kind: "json";
  readonly expected: ExpectedMockRequest;
  readonly body: unknown;
  readonly status: number;
  readonly headers: Readonly<Record<string, string>>;
}

interface QueuedNetworkError {
  readonly kind: "network-error";
  readonly expected: ExpectedMockRequest;
}

interface QueuedPendingResponse {
  readonly kind: "pending";
  readonly expected: ExpectedMockRequest;
}

type QueuedResponse =
  | QueuedJsonResponse
  | QueuedNetworkError
  | QueuedPendingResponse;

export interface MockJsonResponse {
  readonly body: unknown;
  readonly status?: number;
  readonly headers?: Readonly<Record<string, string>>;
}

export interface MockFetchController {
  readonly fetch: typeof globalThis.fetch;
  readonly requests: readonly MockRequestRecord[];
  enqueueJson(
    expected: ExpectedMockRequest,
    response: MockJsonResponse,
  ): void;
  enqueueProblem(
    expected: ExpectedMockRequest,
    problem: Readonly<Record<string, unknown>>,
  ): void;
  enqueueNetworkError(expected: ExpectedMockRequest): void;
  enqueuePending(expected: ExpectedMockRequest): void;
  assertExhausted(): void;
}

function normalizeHeaders(input: HeadersInit | undefined): Readonly<Record<string, string>> {
  const normalized: Record<string, string> = {};
  for (const [name, value] of new Headers(input).entries()) {
    normalized[name.toLowerCase()] = value;
  }
  return Object.freeze(normalized);
}

function recordRequest(input: RequestInfo | URL, init?: RequestInit): MockRequestRecord {
  if (typeof input !== "string" && !(input instanceof URL)) {
    throw new UnexpectedMockRequestError();
  }
  return Object.freeze({
    method: (init?.method ?? "GET").toUpperCase(),
    url: input.toString(),
    headers: normalizeHeaders(init?.headers),
    body: typeof init?.body === "string" ? init.body : undefined,
    credentials: init?.credentials,
  });
}

function assertExpected(
  actual: MockRequestRecord,
  expected: ExpectedMockRequest,
): void {
  if (
    actual.method !== expected.method.toUpperCase() ||
    actual.url !== expected.url
  ) {
    throw new UnexpectedMockRequestError();
  }
}

function pendingResponse(signal: AbortSignal | null | undefined): Promise<Response> {
  return new Promise((_, reject) => {
    const abort = (): void => {
      reject(new DOMException("Mock request aborted", "AbortError"));
    };
    if (signal?.aborted) {
      abort();
      return;
    }
    signal?.addEventListener("abort", abort, { once: true });
  });
}

export function createMockFetch(): MockFetchController {
  const requests: MockRequestRecord[] = [];
  const queue: QueuedResponse[] = [];

  const fetch: typeof globalThis.fetch = async (input, init) => {
    const request = recordRequest(input, init);
    requests.push(request);
    const response = queue.shift();
    if (!response) {
      throw new UnexpectedMockRequestError();
    }
    assertExpected(request, response.expected);
    if (response.kind === "network-error") {
      throw new TestkitNetworkError();
    }
    if (response.kind === "pending") {
      return pendingResponse(init?.signal);
    }
    return new Response(JSON.stringify(response.body), {
      status: response.status,
      headers: {
        "Content-Type": "application/json",
        ...response.headers,
      },
    });
  };

  return {
    fetch,
    requests,
    enqueueJson(expected, response) {
      queue.push({
        kind: "json",
        expected,
        body: response.body,
        status: response.status ?? 200,
        headers: response.headers ?? {},
      });
    },
    enqueueProblem(expected, problem) {
      queue.push({
        kind: "json",
        expected,
        body: problem,
        status:
          typeof problem.status === "number" ? problem.status : 500,
        headers: {
          "Content-Type": "application/problem+json",
        },
      });
    },
    enqueueNetworkError(expected) {
      queue.push({ kind: "network-error", expected });
    },
    enqueuePending(expected) {
      queue.push({ kind: "pending", expected });
    },
    assertExhausted() {
      if (queue.length !== 0) {
        throw new TestkitAssertionError("Mock response queue is not exhausted");
      }
    },
  };
}

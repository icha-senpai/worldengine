export interface Freshness {
  updatedAt?: string | null;
  delayed: boolean;
  retryAfter?: number;
  stored?: boolean;
  pending?: number;
}
export interface DataResult<T> {
  data: T;
  sourceKey?: string;
  refresh: Freshness;
  error: string | null;
}
interface CollectionResponse {
  key: string;
  payload: string;
  error?: string | null;
  updatedAt?: bigint;
  retryAt: bigint;
}
export function collectionResult<T>(
  response: CollectionResponse,
  decode: (payload: string) => T,
  now = Date.now(),
): DataResult<T> {
  const retryAfter = Math.max(
    0,
    Math.ceil((Number(response.retryAt / 1000n) - now) / 1000),
  );
  if (!response.payload) {
    const error = Object.assign(
      new Error(
        response.error || "Data is temporarily unavailable. Try again shortly.",
      ),
      { retryAfter },
    );
    throw error;
  }
  return {
    sourceKey: response.key,
    data: decode(response.payload),
    error: response.error || null,
    refresh: {
      updatedAt: response.updatedAt
        ? new Date(Number(response.updatedAt / 1000n)).toISOString()
        : null,
      delayed: Boolean(response.error) || retryAfter > 0,
      retryAfter,
    },
  };
}

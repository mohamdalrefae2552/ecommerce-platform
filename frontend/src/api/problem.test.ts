import { describe, expect, it } from 'vitest';
import {
  NetworkError,
  ProblemError,
  isProblemCode,
  isProblemContentType,
  networkErrorMessage,
  problemMessages,
  unexpectedResponseMessage,
  userMessage,
} from './problem';

const ARABIC = /[؀-ۿ]/;

describe('problemMessages', () => {
  it('has a distinct Arabic message for every contract code', () => {
    const messages = Object.values(problemMessages);
    for (const message of messages) expect(message).toMatch(ARABIC);
    expect(new Set(messages).size).toBe(messages.length);
  });
});

describe('isProblemCode', () => {
  it('accepts contract codes only', () => {
    expect(isProblemCode('INSUFFICIENT_STOCK')).toBe(true);
    expect(isProblemCode('insufficient_stock')).toBe(false);
    expect(isProblemCode('toString')).toBe(false);
    expect(isProblemCode(404)).toBe(false);
  });
});

describe('isProblemContentType', () => {
  it.each([
    ['application/problem+json', true],
    ['application/problem+json; charset=utf-8', true],
    ['APPLICATION/PROBLEM+JSON', true],
    ['application/json', false],
    ['text/html', false],
    [null, false],
  ])('%s → %s', (contentType, expected) => {
    expect(isProblemContentType(contentType)).toBe(expected);
  });
});

describe('userMessage', () => {
  it('uses the code message, never the server detail', () => {
    const error = new ProblemError(
      {
        type: 'https://ecommerce.local/problems/forbidden',
        title: 'Forbidden',
        status: 403,
        detail: 'server text',
        code: 'FORBIDDEN',
        trace_id: 't',
      },
      403,
      't',
    );
    expect(userMessage(error)).toBe(problemMessages.FORBIDDEN);
  });

  it('has a clear message for a lost connection and for unknown errors', () => {
    expect(userMessage(new NetworkError(new TypeError('failed')))).toBe(networkErrorMessage);
    expect(userMessage(new Error('bug'))).toBe(unexpectedResponseMessage);
  });
});

export class TestkitAssertionError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "TestkitAssertionError";
  }
}

export class TestkitNetworkError extends Error {
  constructor() {
    super("Mock network failure");
    this.name = "TestkitNetworkError";
  }
}

export class UnexpectedMockRequestError extends Error {
  constructor() {
    super("Unexpected mock request");
    this.name = "UnexpectedMockRequestError";
  }
}

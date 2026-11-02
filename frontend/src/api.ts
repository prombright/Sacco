import axios, { AxiosError } from 'axios'

/**
 * The API client. Every reply is { success, data } or { success: false, message, errors };
 * call sites get `data` back, or an ApiError carrying the message to show.
 */

export const http = axios.create({
  baseURL: import.meta.env.BASE_URL + 'api',
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
})

let csrfToken: string | null = null
export function setCsrfToken(token: string | null) {
  csrfToken = token
}

http.interceptors.request.use((config) => {
  if (csrfToken && config.method && config.method.toLowerCase() !== 'get') {
    config.headers['X-CSRF-Token'] = csrfToken
  }
  return config
})

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public errors: Record<string, string> = {},
  ) {
    super(message)
  }
}

/** Called when the API says the session is gone, or the password must be changed first */
let onAuthProblem: (status: number, code?: string) => void = () => {}
export function handleAuthProblems(handler: typeof onAuthProblem) {
  onAuthProblem = handler
}

async function request<T>(method: string, url: string, body?: unknown, params?: Record<string, unknown>): Promise<T> {
  try {
    const res = await http.request({ method, url, data: body, params })
    return res.data.data as T
  } catch (e) {
    const err = e as AxiosError<{ message?: string; errors?: Record<string, string> }>
    const status = err.response?.status ?? 0
    const payload = err.response?.data
    if (status === 401 || status === 419 || payload?.errors?.code === 'must_change_password') {
      onAuthProblem(status, payload?.errors?.code)
    }
    throw new ApiError(
      payload?.message ?? (status === 0 ? 'Can\'t reach the server. Check your connection.' : 'Something went wrong.'),
      status,
      payload?.errors ?? {},
    )
  }
}

export const api = {
  get: <T>(url: string, params?: Record<string, unknown>) => request<T>('get', url, undefined, params),
  post: <T>(url: string, body?: unknown) => request<T>('post', url, body),
  patch: <T>(url: string, body?: unknown) => request<T>('patch', url, body),
  delete: <T>(url: string) => request<T>('delete', url),
}

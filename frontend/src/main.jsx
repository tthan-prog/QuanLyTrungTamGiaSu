import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'

// Guard duplicate in-flight requests caused by rapid clicks/submits.
const originalFetch = window.fetch.bind(window)
const inFlightRequests = new Map()

function hasAuthHeader(init, input) {
  try {
    const h = new Headers(init?.headers || (typeof input === 'object' ? input?.headers : undefined))
    return h.has('Authorization')
  } catch {
    return false
  }
}

window.fetch = async (input, init = {}) => {
  const method = String(init?.method || 'GET').toUpperCase()
  const url = typeof input === 'string' ? input : input?.url || ''

  let bodyKey = ''
  if (typeof init?.body === 'string') {
    bodyKey = init.body
  } else if (init?.body instanceof FormData) {
    bodyKey = '__FORM_DATA__'
  } else if (init?.body != null) {
    try {
      bodyKey = JSON.stringify(init.body)
    } catch {
      bodyKey = '__NON_SERIALIZABLE_BODY__'
    }
  }

  const requestKey = `${method}:${url}:${bodyKey}`

  if (!inFlightRequests.has(requestKey)) {
    const requestPromise = originalFetch(input, init).finally(() => {
      inFlightRequests.delete(requestKey)
    })
    inFlightRequests.set(requestKey, requestPromise)
  }

  const response = await inFlightRequests.get(requestKey)

  // Token hết hạn / không hợp lệ: chỉ xử lý khi chính request này có gửi Authorization
  // và không phải các endpoint đăng nhập (đăng nhập sai cũng có thể trả 401).
  if (response.status === 401 && hasAuthHeader(init, input) && !/\/(auth|admin)\/login/.test(url)) {
    window.dispatchEvent(new CustomEvent('auth:expired'))
  }

  return response.clone()
}

createRoot(document.getElementById('root')).render(
  <App />,
)

import { QueryClient, QueryFunction } from "@tanstack/react-query";

// Environment-aware API configuration
const isDevelopment = import.meta.env.DEV || window.location.hostname === 'localhost' || window.location.hostname.includes('replit.dev');
const API_BASE_URL = isDevelopment ? '' : 'https://cybaemtech.com/php/api';

async function throwIfResNotOk(res: Response) {
  if (!res.ok) {
    const text = (await res.text()) || res.statusText;
    throw new Error(`${res.status}: ${text}`);
  }
}

// Helper function to construct full API URL
function getApiUrl(endpoint: string): string {
  if (isDevelopment) {
    // In development, convert PHP endpoints back to Node.js format
    const nodeEndpoint = endpoint
      .replace('/auth.php?action=login', '/api/login')
      .replace('/auth.php?action=register', '/api/register')
      .replace('/auth.php?action=logout', '/api/logout')
      .replace('/auth.php', '/api/user')
      .replace('/tickets.php?user=my', '/api/tickets/my')
      .replace(/\/tickets\.php\?id=(\d+)&action=comment/, '/api/tickets/$1/comments')
      .replace(/\/tickets\.php\?id=(\d+)/, '/api/tickets/$1')
      .replace('/tickets.php', '/api/tickets')
      .replace('/dashboard.php', '/api/dashboard')
      .replace(/\/categories\.php\?id=(\d+)/, '/api/categories/$1')
      .replace(/\/categories\.php\?parentId=(\d+)/, '/api/categories/$1/subcategories')
      .replace('/categories.php', '/api/categories')
      .replace(/\/users\.php\?id=(\d+)/, '/api/users/$1')
      .replace('/users.php', '/api/users')
      .replace('/faqs.php', '/api/faqs')
      .replace('/chat.php', '/api/chat');
    return nodeEndpoint;
  }
  return `${API_BASE_URL}${endpoint}`;
}

export async function apiRequest(
  method: string,
  url: string,
  data?: unknown | undefined,
): Promise<Response> {
  const fullUrl = url.startsWith('/') ? getApiUrl(url) : url;
  const res = await fetch(fullUrl, {
    method,
    headers: data ? { "Content-Type": "application/json" } : {},
    body: data ? JSON.stringify(data) : undefined,
    credentials: "include",
  });

  await throwIfResNotOk(res);
  return res;
}

type UnauthorizedBehavior = "returnNull" | "throw";
export const getQueryFn: <T>(options: {
  on401: UnauthorizedBehavior;
}) => QueryFunction<T> =
  ({ on401: unauthorizedBehavior }) =>
  async ({ queryKey }) => {
    const url = queryKey[0] as string;
    const fullUrl = url.startsWith('/') ? getApiUrl(url) : url;
    const res = await fetch(fullUrl, {
      credentials: "include",
    });

    if (unauthorizedBehavior === "returnNull" && res.status === 401) {
      return null;
    }

    await throwIfResNotOk(res);
    return await res.json();
  };

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      queryFn: getQueryFn({ on401: "throw" }),
      refetchInterval: false,
      refetchOnWindowFocus: false,
      staleTime: Infinity,
      retry: false,
    },
    mutations: {
      retry: false,
    },
  },
});

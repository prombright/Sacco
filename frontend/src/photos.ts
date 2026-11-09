import { http } from '@/api'
import type { User } from '@/types'

/** A photo path from the API as an address the browser can load */
export function photoSrc(url: string | null | undefined): string | null {
  if (!url) return null
  return /^(https?:)?\/\//.test(url) || url.startsWith('/') ? url : import.meta.env.BASE_URL + url
}

/** Uploads the signed-in person's photo; the server redraws it as a small square JPEG */
export async function uploadPhoto(file: File): Promise<User> {
  const body = new FormData()
  body.append('photo', file)
  const res = await http.post('/me/photo', body, { headers: { 'Content-Type': 'multipart/form-data' } })
  return res.data.data.user as User
}

export async function removePhoto(): Promise<User> {
  const res = await http.delete('/me/photo')
  return res.data.data.user as User
}

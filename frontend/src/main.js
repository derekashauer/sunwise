import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { setupPwa } from './pwa'
import './assets/main.css'

// Listen for the install prompt before the app mounts, since the browser can
// fire it right away.
setupPwa()

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')

const express = require('express');
const cors = require('cors');
const session = require('express-session');
const fs = require('fs');
const path = require('path');

const app = express();
const PORT = 8080;

app.use(cors({ credentials: true, origin: true }));
app.use(express.json());
app.use(express.static(__dirname));

app.use(session({
    secret: 'cheva-secret',
    resave: false,
    saveUninitialized: true,
    cookie: { secure: false }
}));

const DATA_FILE = path.join(__dirname, 'data.json');

function loadData() {
    if (!fs.existsSync(DATA_FILE)) {
        return {
            tasks: [{ id: 1, task_name: 'Selamat datang!', list_order: 1, is_completed: 0, due_date: null, pomodoro_count: 0, category_color: 'transparent', completed_date: null }],
            streak_data: { current: 0, longest: 0, last_date: null },
            notified_deadlines: []
        };
    }
    return JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
}

function saveData(data) {
    fs.writeFileSync(DATA_FILE, JSON.stringify(data, null, 2));
}

function checkDeadlineNotifications(tasks, notified) {
    const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });
    const tomorrowObj = new Date();
    tomorrowObj.setDate(tomorrowObj.getDate() + 1);
    const tomorrow = tomorrowObj.toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });

    let notifications = [];

    tasks.forEach(task => {
        if (!task.is_completed && task.due_date) {
            const dueDate = task.due_date;
            const notifyKey = task.id + '_' + dueDate;

            if (dueDate === today && !notified.includes(notifyKey + '_today')) {
                notifications.push({ type: 'today', task_name: task.task_name, due_date: dueDate });
                notified.push(notifyKey + '_today');
            }

            if (dueDate === tomorrow && !notified.includes(notifyKey + '_tomorrow')) {
                notifications.push({ type: 'tomorrow', task_name: task.task_name, due_date: dueDate });
                notified.push(notifyKey + '_tomorrow');
            }
        }
    });

    const weekAgoObj = new Date();
    weekAgoObj.setDate(weekAgoObj.getDate() - 7);
    const weekAgo = weekAgoObj.toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });
    
    notified = notified.filter(n => {
        const parts = n.split('_');
        if (parts.length >= 2) {
            return parts[1] >= weekAgo;
        }
        return true;
    });

    return { notifications, newNotified: notified };
}

function refreshStreak(tasks, streak) {
    const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });
    const yesterdayObj = new Date();
    yesterdayObj.setDate(yesterdayObj.getDate() - 1);
    const yesterday = yesterdayObj.toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });

    if (streak.current === 0 && streak.last_date) {
        streak.last_date = null;
    }

    let completedToday = 0;
    tasks.forEach(t => {
        if (t.is_completed == 1 && t.completed_date === today) {
            completedToday++;
        }
    });

    if (completedToday > 0) {
        if (!streak.last_date) {
            streak.current = 1;
            streak.last_date = today;
            streak.longest = Math.max(1, streak.longest || 0);
        } else if (streak.last_date === yesterday) {
            streak.current++;
            streak.last_date = today;
            if (streak.current > (streak.longest || 0)) {
                streak.longest = streak.current;
            }
        } else if (streak.last_date === today) {
        } else {
            streak.current = 1;
            streak.last_date = today;
        }
    } else {
        if (streak.last_date === today) {
            streak.current = Math.max(0, streak.current - 1);
            streak.last_date = streak.current > 0 ? yesterday : null;
        } else if (streak.last_date && streak.last_date < yesterday) {
            streak.current = 0;
            streak.last_date = null;
        }
    }

    return streak;
}

app.all('/api/api.php', (req, res) => {
    const action = req.query.action || '';
    const input = req.body || {};
    const ADMIN_PASS = 'cepayakinn';
    const isAdmin = () => req.session.is_admin === true;

    let data = loadData();
    let { tasks, streak_data: streak, notified_deadlines: notified } = data;

    tasks.sort((a, b) => (a.list_order || 0) - (b.list_order || 0));
    let message = '';

    switch (action) {
        case 'get_all':
            if (!req.session.logged_in) {
                return res.json({ status: 'locked', isAdmin: false, streak: { current: 0, longest: 0, last_date: null } });
            }
            const dlResult = checkDeadlineNotifications(tasks, notified);
            data.notified_deadlines = dlResult.newNotified;
            saveData(data);
            return res.json({ status: 'success', data: tasks, isAdmin: isAdmin(), streak, deadline_notifications: dlResult.notifications });

        case 'check_deadlines':
            if (!req.session.logged_in) return res.json({ status: 'success', notifications: [] });
            const checkResult = checkDeadlineNotifications(tasks, notified);
            data.notified_deadlines = checkResult.newNotified;
            saveData(data);
            return res.json({ status: 'success', notifications: checkResult.notifications });

        case 'get_streak':
            if (!req.session.logged_in) return res.json({ status: 'success', streak: { current: 0, longest: 0, last_date: null } });
            return res.json({ status: 'success', streak: refreshStreak(tasks, streak) });

        case 'login':
            if (input.password === ADMIN_PASS) {
                req.session.logged_in = true;
                req.session.is_admin = true;
                streak = refreshStreak(tasks, streak);
                data.streak_data = streak;
                const loginDl = checkDeadlineNotifications(tasks, notified);
                data.notified_deadlines = loginDl.newNotified;
                saveData(data);
                return res.json({ status: 'success', data: tasks, streak, deadline_notifications: loginDl.notifications });
            } else {
                return res.json({ status: 'error', message: 'Password salah' });
            }

        case 'logout':
            req.session.destroy();
            return res.json({ status: 'success' });

        case 'add':
        case 'delete':
        case 'reset':
        case 'clear_completed':
        case 'uncheck_all':
        case 'swap':
        case 'reorder':
        case 'update_due_date':
        case 'bulk_add':
            if (!isAdmin()) return res.json({ status: 'error', message: 'Unauthorized' });

            if (action === 'add') {
                const max = tasks.length ? Math.max(...tasks.map(t => t.list_order || 0)) : 0;
                tasks.push({
                    id: Date.now(),
                    task_name: input.task ? input.task.trim() : '',
                    list_order: max + 1,
                    is_completed: 0,
                    category_color: input.color || 'transparent',
                    due_date: input.due_date || null,
                    pomodoro_count: 0
                });
                message = 'Tugas berhasil ditambahkan!';
            } else if (action === 'bulk_add') {
                const newTasks = input.tasks || [];
                let max = tasks.length ? Math.max(...tasks.map(t => t.list_order || 0)) : 0;
                newTasks.forEach(nt => {
                    tasks.push({
                        id: Date.now() + Math.floor(Math.random() * 1000),
                        task_name: nt.task,
                        list_order: ++max,
                        is_completed: 0,
                        category_color: 'transparent',
                        due_date: nt.due_date || null,
                        pomodoro_count: 0
                    });
                });
                message = `Berhasil mengimpor ${newTasks.length} tugas!`;
            } else if (action === 'delete') {
                tasks = tasks.filter(t => t.id != input.id);
                message = 'Tugas berhasil dihapus!';
            } else if (action === 'reset') {
                tasks = [{ id: 1, task_name: 'List direset', list_order: 1, is_completed: 0, due_date: null, pomodoro_count: 0, category_color: 'transparent' }];
                message = 'List berhasil direset!';
            } else if (action === 'clear_completed') {
                tasks = tasks.filter(t => !t.is_completed || t.is_completed == 0);
                message = 'Tugas selesai berhasil dibersihkan!';
            } else if (action === 'uncheck_all') {
                tasks.forEach(t => t.is_completed = 0);
                message = 'Semua tugas di-uncheck!';
            } else if (action === 'swap') {
                tasks.forEach(t => {
                    if (t.id == input.id1) t.list_order = input.order2;
                    else if (t.id == input.id2) t.list_order = input.order1;
                });
            } else if (action === 'reorder') {
                const newOrder = input.order || [];
                tasks.forEach(t => {
                    const index = newOrder.indexOf(t.id);
                    if (index !== -1) t.list_order = index;
                });
            } else if (action === 'update_due_date') {
                tasks.forEach(t => { if (t.id == input.id) t.due_date = input.due_date; });
                message = 'Deadline berhasil diupdate!';
            }

            data.tasks = tasks;
            data.streak_data = refreshStreak(tasks, streak);
            saveData(data);
            return res.json({ status: 'success', data: tasks, message, streak: data.streak_data });

        case 'toggle':
            if (!req.session.logged_in) return res.json({ status: 'error' });
            const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Jakarta' });
            
            tasks.forEach(t => {
                if (t.id == input.id) {
                    const wasCompleted = t.is_completed == 1 || t.is_completed === true;
                    t.is_completed = wasCompleted ? 0 : 1;
                    t.completed_date = t.is_completed == 1 ? today : null;
                }
            });
            data.tasks = tasks;
            data.streak_data = refreshStreak(tasks, streak);
            saveData(data);
            return res.json({ status: 'success', data: tasks, streak: data.streak_data });

        case 'pomodoro_complete':
            if (!req.session.logged_in) return res.json({ status: 'error' });
            tasks.forEach(t => {
                if (t.id == input.id) t.pomodoro_count = (t.pomodoro_count || 0) + 1;
            });
            data.tasks = tasks;
            saveData(data);
            return res.json({ status: 'success', data: tasks });

        default:
            return res.json({ status: 'error', message: 'Invalid action' });
    }
});

app.listen(PORT, () => {
    console.log(`===========================================`);
    console.log(`🚀 API SERVER BERJALAN LOKAL!`);
    console.log(`👉 Buka: http://localhost:${PORT}`);
    console.log(`===========================================`);
});

import sys, re
files = ['my_document.php', 'student_calendar.php', 'submissions.php', 'student_notifications.php', 'checklist.php']
for file in files:
    try:
        with open(file, 'r', encoding='utf-8') as f:
            content = f.read()
            
        # 1. Update SELECT query
        content = content.replace(
            'SELECT first_name, middle_name, last_name, student_id, email, profile_picture, year_level, course FROM users WHERE id = ?',
            'SELECT first_name, middle_name, last_name, student_id, email, profile_picture, year_level, course, section FROM users WHERE id = ?'
        )
        
        # 2. Add variables extraction
        target_vars = "$user_year_level = $user_data['year_level'] ?? '';\n$user_course = $user_data['course'] ?? '';"
        replacement_vars = "$user_year_level = $user_data['year_level'] ?? '';\n$user_course = $user_data['course'] ?? '';\n$user_section = $user_data['section'] ?? '';\n\n$display_year_section = '';\nif (!empty($user_year_level)) {\n    $year_num = substr($user_year_level, 0, 1);\n    $display_year_section = $year_num . $user_section;\n}"
        if target_vars in content and replacement_vars not in content:
            content = content.replace(target_vars, replacement_vars)
            
        # 3. Update Sidebar HTML display
        target_html = """                <?php if (!empty($user_course) || !empty($user_year_level)): ?>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        <?= htmlspecialchars(trim($user_course . ' ' . $user_year_level)) ?>
                    </div>
                <?php endif; ?>"""
        replacement_html = """                <?php if (!empty($user_course) || !empty($display_year_section)): ?>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        <?= htmlspecialchars(trim($user_course . ' ' . $display_year_section)) ?>
                    </div>
                <?php endif; ?>"""
        if target_html in content:
            content = content.replace(target_html, replacement_html)
            
        # Remove enrolled status if present
        target_enrolled = """            <div class="sidebar-status">
                <i class="fas fa-check-circle"></i> Enrolled<br>
                <span style="font-weight: 400; color: #15803d; font-size: 10px;">First Semester 2024-2025</span>
            </div>"""
        content = content.replace(target_enrolled, '')
            
        with open(file, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f'Successfully updated {file}')
    except Exception as e:
        print(f'Error updating {file}: {e}')

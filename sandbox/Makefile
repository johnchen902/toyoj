CFLAGS += -std=gnu11 -D_GNU_SOURCE
EXE = sandbox
OBJ = sandbox.o child.o child-execute.o grandchild.o

.PHONY: debug
debug: CFLAGS += -Wall -Wextra -g -Og
debug: $(EXE)

# Use "env CFLAGS=... make release" to change release flag
.PHONY: release
release: $(EXE)

$(EXE): $(OBJ)

sandbox.o: child.h
child.o: child.h child-execute.h
child-execute.o: child-execute.h grandchild.h
grandchild.o: grandchild.h

.PHONY: clean
clean:
	$(RM) $(EXE) $(OBJ)
